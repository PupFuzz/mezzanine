#!/usr/bin/env bash
# ── VENDORED from coord 0.77.0 — the selftest of the board-traceability gate. A body edit reds bin/vendor-pin-check.sh until declared below. ──
#
#   upstream repo     PupFuzz/agent-board-framework  (PRIVATE — a public CI runner cannot clone it)
#   upstream path     plugins/coord/templates/traceability/dl-traceability.selftest.sh
#   vendored from     afaf9a10022fbcab33287155076b1267b422fce4   (marketplace, the v0.77.0 release merge)
#   plugin version    coord 0.77.0
#   upstream sha256   b19d63acdfe7c52f6c7196c6a8cfd55ad454d3467f16fcec05316939da605e9c
#
# That sha256 is the WHOLE upstream file; `bin/vendor-pin-check.sh` pins the BODY (from the first
# line that is neither blank nor a comment), which is a different figure. Re-derive this one
# against a marketplace clone:   git show afaf9a10:plugins/coord/templates/traceability/dl-traceability.selftest.sh | sha256sum
#
# DECLARED LOCAL EDITS — none. Everything below this block is upstream's byte for byte, the upstream
# header included (checked by diff against the commit above); the body pin holds the body there.
#
# RUN IT AGAINST THIS REPOSITORY'S COPY, which is not where upstream's default path looks — pass it:
#     bash bin/dl-traceability.selftest.sh .github/workflows/dl-traceability.yml
# That is exactly what `.github/workflows/dl-traceability-selftest.yml` runs. With no argument the
# suite looks for `bin/workflows/dl-traceability.yml`, finds nothing, and FAILS by name rather than
# testing nothing. ShellCheck's findings on this file are recorded in bin/shell-lint.baseline.tsv
# rather than annotated in the body, so the body stays upstream's.
#
# TO RE-VENDOR: copy upstream's file over everything below this block, then re-pin with the command
# `bin/vendor-pin-check.sh` prints on a red.
# ── END MEZZANINE VENDOR HEADER ────────────────────────────────────────────────────────────────
# dl-traceability.selftest.sh — proves the shipped traceability gate BLOCKS, PASSES, and
# distinguishes the two. Both directions, because only the second one separates a working
# check from a broken one: a check that refuses everything also "reds when it should".
#
# OWNED BY the coord plugin's `docs/DL-TRACEABILITY.md` (§ 8 Proving it can fail), which owns
# the adoption decision and the failure semantics this suite asserts. The accept-set's own owner
# is the coord plugin's `docs/BRIDGE-WRITEBACK.md`; the boundary asymmetry is § 6.1 of its
# DL-TRACEABILITY.md.
#
# ⛔ WHY THIS EXISTS AT ALL. The template it tests ships BLOCKING, and the whole argument for
# shipping it that way is that an advisory gate is indistinguishable from a working one. A
# blocking gate that has never been OBSERVED blocking is the same defect at a new layer: it
# looks enforced from every surface anyone reads, and nothing has ever made it fire. So the
# proof is executable, it runs in CI on every PR, and it asserts on the gate's own output.
#
# WHAT IT RUNS. Every leg executes the SHIPPED YAML's own shell, extracted verbatim from
# between the `# --- <name>` markers of workflows/dl-traceability.yml. Nothing here
# re-implements the gate, so an assertion cannot keep passing against logic the template no
# longer ships.
#
# ZERO NETWORK, AND THE SEAMS ARE STILL REAL. Only `gh` is replaced, by a PATH stub that runs
# the CALLER'S OWN `--jq` expression against a fixture payload in the GitHub API's shape. The
# shipped seam bodies therefore DO execute here — a gate that stops reading
# `.previous_filename`, or stops base64-encoding, reds in this suite rather than at an
# adopter's first malicious rename. What is NOT covered: `gh`'s own pagination and auth
# behaviour, and the live API's shape. The first live run is what exercises those.
#
# THE CONTROLS. §E refuses to run at all if extraction came back empty (a suite testing an
# empty string passes every assertion). §M mutates the shipped block to delete one fail-closed
# branch at a time and requires the matching leg to go GREEN — without that, a fail-closed leg
# could be red because the fixture is odd rather than because the shipped logic is closed.
#
# ⛔ THE ONE THING THIS SUITE CANNOT ESTABLISH is whether the accept-set matches the board
# writeback. That is a different question with a different owner and its own guard, which lives
# in the framework repo and does not ship: `.githooks/dl-traceability-accept-set.selftest.py`
# THERE drives the writeback's own constants and this template's arms over one corpus. This
# suite asserts BEHAVIOUR; that one asserts PARITY. Neither substitutes for the other, and an
# adopter who copied this template has the first and not the second.
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# WHERE THE TEMPLATE IS, and how to say otherwise. The default is the layout this suite ships
# in (`workflows/dl-traceability.yml` NEXT TO this file), which is also what you get by dropping
# the suite at `.github/dl-traceability.selftest.sh` in an adopting repo. If your copy lives
# anywhere else — the usual case, because most repos keep the workflow in `.github/workflows/`
# and the suite in a `tests/` or `scripts/` directory — pass the path as $1 or set $WF:
#     ./dl-traceability.selftest.sh .github/workflows/dl-traceability.yml
# ⛔ Point it at YOUR copy, not at the pristine template: a suite run against a file the repo
# does not use is a green that certifies nothing about the gate that is actually blocking.
WF="${1:-${WF:-$HERE/workflows/dl-traceability.yml}}"
FAILURES=0
CHECKS=0

fail() { echo "  FAIL: $1"; FAILURES=$((FAILURES + 1)); }
ok()   { echo "  ok:   $1"; CHECKS=$((CHECKS + 1)); }
# `has HAYSTACK NEEDLE` — a LITERAL substring test, pipe-free. Name and body are byte-identical
# to `plugins/coord/hooks/bin/_selftest_match.sh`, which owns the argument and the measurement;
# IN-FILE rather than sourced because this suite SHIPS (dropped at
# `.github/dl-traceability.selftest.sh` in an adopting repo), where that path is not one it can
# name. A FRAMEWORK-REPO guard keeps it honest — it does not ship, so nothing in your install
# runs it and nothing here depends on it being present:
# `.githooks/membership-primitive-drift.selftest.py` reds if this copy stops being
# byte-identical to the authority.
# ⛔ NOT `printf '%s' "$h" | grep -q "$n"`, which is what every site below used to be.
# `grep -q` exits ZERO ON ITS FIRST MATCH, closing the pipe; the `printf` then takes SIGPIPE and
# dies 141, and under `pipefail` the PIPELINE's status is 141, so the predicate answers "absent"
# about something that is present — the direction nothing notices, and in an ASSERTION it turns
# a passing gate into a spurious FAIL (or, negated, hides a real one). It is gated on payload
# size, so it is right on every small fixture and wrong on the big one. `case` has no writer to
# signal. The needle is DOUBLE-QUOTED so it stays literal instead of being read as a glob — that
# is what reproduces `grep -F`. Ruled by coord card#8080, where the measurement lives.
has() { case "$1" in *"$2"*) return 0 ;; *) return 1 ;; esac; }
finish() {
  echo
  if [ "$FAILURES" -gt 0 ]; then
    echo "dl-traceability selftest: FAIL ($FAILURES failed, $CHECKS passed)"; exit 1
  fi
  echo "dl-traceability selftest: all $CHECKS checks pass"; exit 0
}

for tool in jq grep base64 sed; do
  # A missing prerequisite is NAMED, never skipped: a silent skip reports a green suite that
  # ran none of these legs.
  command -v "$tool" >/dev/null 2>&1 \
    || { fail "PREREQUISITE MISSING: $tool is not on PATH; this suite does not skip legs"; finish; }
done
[ -f "$WF" ] || { fail "no workflow to test at $WF — pass the path to your copy as \$1 (or set \$WF)"; finish; }

extract() {  # $1=block-name -> the block body, de-indented by 10
  sed -n "/--- $1\$/,/--- end $1\$/p" "$WF" \
    | grep -v -- "--- $1\$" \
    | grep -v -- "--- end $1\$" \
    | sed 's/^          //'
}

# ── §E extraction controls: every leg below is worthless if these are empty ──────────────
BLOCKS=""
for name in dl-traceability-config dl-traceability-lib dl-traceability-main; do
  body="$(extract "$name")"
  if [ -z "$body" ]; then
    fail "extraction of '$name' returned EMPTY — the yml markers moved; this selftest is testing nothing"
    finish
  fi
  BLOCKS="$BLOCKS
$body"
done
ok "3 blocks extracted from the shipped template ($(printf '%s' "$BLOCKS" | wc -l) lines)"
if ! bash -n <(printf 'set -euo pipefail\n%s\n' "$BLOCKS") 2>/dev/null; then
  fail "the extracted blocks do not PARSE — every leg below would red on a syntax error"
  finish
fi
ok "extracted blocks parse"

# ── §S the shipped CUSTOMIZE seams: their BODIES run here (only `gh` is stubbed) ─────────
SEAMS="$(sed -n '/CUSTOMIZE-BEGIN traceability-seams/,/CUSTOMIZE-END traceability-seams/p' "$WF")"
missing=""
# `has`, not a pipe into `grep -q` — see `has()` above for why that shape answers "absent"
# about something that is present.
for fn in 'pr_head_sha_now()' 'changed_paths_b64()'; do
  has "$SEAMS" "$fn" || missing="$missing $fn"
done
if [ -n "$missing" ]; then
  fail "the shipped CUSTOMIZE zone no longer defines:$missing — the gate calls them, so the template would fail at run time"
else
  ok "shipped CUSTOMIZE zone defines both API seams (their bodies DO run here, against a gh stub)"
fi
# A CONTRACT assertion, deliberately not dressed up as a behavioural one: `@base64` is what
# delivers a path to the loop as ONE string, which is the precondition for the newline rejection
# §M4 exercises. Dropping it does not have a clean single-mutant behaviour to assert (a raw path
# simply fails to decode), so what is checked here is that it is still spelled.
if has "$SEAMS" '@base64'; then
  ok "the changed-files seam still base64-encodes each path (the precondition for §M4's rejection)"
else
  fail "the changed-files seam no longer spells @base64 — a path containing a newline would reach the predicate as two independently-judged fragments"
fi
# The `continue-on-error` re-mint guard. This template's entire argument is that the advisory
# state is indistinguishable from a working check; the one edit that re-creates it must red.
# COMMENT LINES ARE STRIPPED FIRST — the header talks ABOUT the flag at length, and a naive
# grep reds on the prose that explains why the flag is absent (it did, on the first run).
if has "$(grep -vE '^[[:space:]]*#' "$WF")" 'continue-on-error'; then
  fail "the shipped template carries \`continue-on-error\` — that is the advisory state this template exists to refuse. A project that needs a grace period expresses that by NOT ADOPTING YET."
else
  ok "the shipped template carries no \`continue-on-error\` (it ships blocking)"
fi

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/bin"

cat > "$TMP/bin/gh" <<'STUB'
#!/usr/bin/env bash
# `gh api [--paginate] <endpoint> --jq <expr>` stub. It records the invocation, then runs the
# CALLER'S OWN jq expression against a fixture payload — so the shipped seam's jq is what is
# under test, not a re-typed copy of it. `gh --jq` emits raw strings, hence `jq -r`.
set -uo pipefail
printf '%s\n' "$*" >> "$STUB_ARGS"
endpoint=""; expr="."; prev=""
for a in "$@"; do
  case "$a" in repos/*) endpoint="$a" ;; esac
  if [ "$prev" = "--jq" ]; then expr="$a"; fi
  prev="$a"
done
case "$endpoint" in
  */files)
    if [ "${STUB_FAIL_FILES:-0}" = "1" ]; then echo "stub gh: simulated HTTP 403" >&2; exit 1; fi
    src="$STUB_FILES_JSON" ;;
  *)
    if [ "${STUB_FAIL_PR:-0}" = "1" ]; then echo "stub gh: simulated HTTP 403" >&2; exit 1; fi
    src="$STUB_PR_JSON" ;;
esac
jq -r "$expr" < "$src"
STUB
chmod +x "$TMP/bin/gh"

EVENT_SHA="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"

# run_gate <title> <branch> <author> <current-head-sha> <files-json-file> <blocks>
#   -> prints the gate's combined output; returns the gate's exit code.
run_gate() {
  local title="$1" branch="$2" author="$3" sha_now="$4" files="$5" blocks="${6:-$BLOCKS}"
  printf '{"head":{"sha":"%s"}}\n' "$sha_now" > "$TMP/pr.json"
  : > "$TMP/args"
  PATH="$TMP/bin:$PATH" \
  STUB_ARGS="$TMP/args" STUB_PR_JSON="$TMP/pr.json" STUB_FILES_JSON="$files" \
  STUB_FAIL_FILES="${STUB_FAIL_FILES:-0}" STUB_FAIL_PR="${STUB_FAIL_PR:-0}" \
  PR_TITLE="$title" PR_HEAD="$branch" PR_AUTHOR="$author" PR_HEAD_SHA="$EVENT_SHA" \
  PR_NUM="4242" REPO="fixture-org/fixture-repo" GH_TOKEN="stub-token" \
  bash -c "set -euo pipefail
$blocks" 2>&1
}

# A code diff, so limb 2 can never rescue a leg that is supposed to be about tokens.
printf '[{"filename":"app/Service.php"}]\n' > "$TMP/code.json"

# case <name> <title> <branch> <want-exit> <want-substring>
case_gate() {
  local name="$1" title="$2" branch="$3" want_exit="$4" want_sub="$5"
  local files="${6:-$TMP/code.json}" author="${7:-a-human}" sha="${8:-$EVENT_SHA}"
  local out rc
  out="$(run_gate "$title" "$branch" "$author" "$sha" "$files")"; rc=$?
  if [ "$rc" -ne "$want_exit" ]; then
    fail "$name — expected exit $want_exit, got $rc. Output: $out"; return
  fi
  if has "$out" "$want_sub"; then ok "$name"
  else fail "$name — output did not contain '$want_sub'. Output: $out"; fi
}

CORRELATING="board-correlating token"
# ⛔ "limb 2" ALONE IS NOT AN EXEMPT ASSERTION. Every DECLINE message also begins "limb 2
# declined: …", so a leg asserting the loose prefix is satisfied by the exact opposite outcome —
# which is how the §M base64 control passed while proving nothing on the first run. Assert the
# verdict, never the section number.
EXEMPT2="Exempt (limb 2"
ADVISORY="security-advisory id"
GATED="::error title=Board traceability"

echo
echo "── §A the accept-set, ARM BY ARM. The boundary rule is ASYMMETRIC and that asymmetry is"
echo "   the specification (owner: DL-TRACEABILITY.md § 6.1): the CARD arm has NO trailing"
echo "   boundary, the DL/CVE/GHSA arms require one. Each row below is a full end-to-end verdict"
echo "   over a CODE diff, so nothing here is rescued by an exemption."

# ── card arm: correlates. Left-anchored only; glued at TWO OR MORE digits.
case_gate "card arm: card#<id> in the title"        'fix(card#5086): thing' 'chore/x'          0 "$CORRELATING"
case_gate "card arm: card-<id> in the head branch"  'No token here'         'fix/card-5086-x'  0 "$CORRELATING"
case_gate "card arm: glued card<id> at 2 digits"    'No token'              'fix/card42-x'     0 "$CORRELATING"
case_gate "card arm: separated arm takes 1 digit"   'fix(card-3): thing'    'chore/x'          0 "$CORRELATING"
# ⛔ THE CARD ARM'S MISSING TRAILING BOUNDARY IS DELIBERATE. A `_` suffix is the shape that
# motivated removing it upstream; adding a trailing boundary here would silently un-correlate
# these two, and they are the reason the asymmetry exists.
case_gate "card arm: NO trailing boundary — card#3054_fix correlates" 'card#3054_fix' 'chore/x' 0 "$CORRELATING"
case_gate "card arm: NO trailing boundary — card-123-foo correlates"  'card-123-foo'  'chore/x' 0 "$CORRELATING"
# ── card arm: near-misses that must NOT correlate.
case_gate "card arm: glued below the 2-digit floor (card4) is NOT a token" 'card4 fix'   'chore/x' 1 "$GATED"
case_gate "card arm: an ordinary word (card2go) is NOT a token"            'card2go now' 'chore/x' 1 "$GATED"
case_gate "card arm: card_5086 is NOT a token"                            'card_5086'   'chore/x' 1 "$GATED"
case_gate "card arm: spaced card #5086 is NOT a token"                    'card #5086'  'chore/x' 1 "$GATED"
case_gate "card arm: plural cards #5086 is NOT a token"                   'cards #5086' 'chore/x' 1 "$GATED"
case_gate "card arm: no left boundary (discard 5) is NOT a token"         'discard 5 items' 'chore/x' 1 "$GATED"

# ── DL arm: the trailing boundary is REQUIRED. These three rows are the measured defect that
# shipped in an adopting repo when the CARD arm's unboundedness was over-applied to this arm.
case_gate "DL arm: DL-<n> correlates"                        'feat: DL-233 thing' 'chore/x'  0 "$CORRELATING"
case_gate "DL arm: leading zeros collapse (DL-007)"          'feat: DL-007 thing' 'chore/x'  0 "$CORRELATING"
case_gate "DL arm: lowercase dl-7 correlates"                'feat: dl-7 thing'   'chore/x'  0 "$CORRELATING"
case_gate "DL arm: DL-99 followed by a space still correlates" 'DL-99 foo'        'chore/x'  0 "$CORRELATING"
case_gate "DL arm: TRAILING BOUNDARY — DL-99x is NOT a token"   'feat/DL-99x-thing' 'chore/x' 1 "$GATED"
case_gate "DL arm: TRAILING BOUNDARY — DL-231abc is NOT a token" 'DL-231abc'        'chore/x' 1 "$GATED"
case_gate "DL arm: TRAILING BOUNDARY — DL-7_wip is NOT a token"  'chore: DL-7_wip'  'chore/x' 1 "$GATED"
case_gate "DL arm: ADR-233 is NOT a DL token"                    'ADR-233'          'chore/x' 1 "$GATED"

# ── GHSA arm: exactly three 4-char groups, trailing boundary REQUIRED, and it is reported as
# ADVISORY — not correlating — because no card moves on it.
case_gate "GHSA arm: a real id is accepted, and reported as ADVISORY" \
  'Bump nanoid (GHSA-2v37-7h3g-55p8)' 'chore/bump' 0 "$ADVISORY"
case_gate "GHSA arm: an advisory id does NOT claim board correlation" \
  'Bump nanoid (GHSA-2v37-7h3g-55p8)' 'chore/bump' 0 "No card will self-move"
case_gate "GHSA arm: a real id in a branch, package-suffixed, still accepted" \
  'Bump nanoid' 'fix/GHSA-2v37-7h3g-55p8-nanoid' 0 "$ADVISORY"
# ⛔ THE PRIOR INCIDENT, PINNED: an unbounded tail let a PACKAGE NAME supply a group.
case_gate "GHSA arm: a package name is NOT a group (ghsa-2v37-nanoid)" \
  'Bump nanoid' 'fix/ghsa-2v37-nanoid' 1 "$GATED"
case_gate "GHSA arm: TRAILING BOUNDARY — ghsa-abcd-efgh-ijklmnop is NOT an id" \
  'Bump x' 'fix/ghsa-abcd-efgh-ijklmnop' 1 "$GATED"
case_gate "GHSA arm: a two-group form is NOT an id" \
  'Bump x' 'fix/ghsa-2v37-7h3g' 1 "$GATED"

# ── CVE arm: 4+ digit sequence part, trailing boundary REQUIRED.
case_gate "CVE arm: a real id is accepted, and reported as ADVISORY" \
  'Bump form-data for CVE-2026-12143' 'chore/bump' 0 "$ADVISORY"
case_gate "CVE arm: lowercase in a branch is accepted" \
  'Bump commonmark' 'fix/cve-2026-71488-commonmark-2.9.0' 0 "$ADVISORY"
case_gate "CVE arm: CVE-2026-1 is not an id (sequence part is 4+ digits)" \
  'CVE-2026-1' 'chore/x' 1 "$GATED"
case_gate "CVE arm: TRAILING BOUNDARY — cve-2026-1214abcd is NOT an id" \
  'Bump x' 'fix/cve-2026-1214abcd' 1 "$GATED"

echo
echo "── §N the BOUNDARY IS ASCII, and a non-ASCII neighbour is not one. Under LC_ALL=C every"
echo "   byte of a non-ASCII character is non-word, so \`\\b\` would find a boundary beside one"
echo "   and accept a token the board writeback (python \`re\`, where those are WORD characters)"
echo "   cannot parse — a FALSE GREEN on a merge-blocking check. These rows are that class."

# ⛔ THE FALSE-GREEN DIRECTION, CLOSED. Each row carries a well-formed token GLUED to a
# non-ASCII word character. The writeback parses none of them, so the gate must accept none.
case_gate "boundary: 更 before a card token is NOT a boundary" '更card-42 の修正' 'chore/x' 1 "$GATED"
case_gate "boundary: ٣ before a card token is NOT a boundary" '٣card-42'        'chore/x' 1 "$GATED"
case_gate "boundary: 更 after a DL token is NOT a boundary"    'feat/DL-231更'    'chore/x' 1 "$GATED"
case_gate "boundary: é after a DL token is NOT a boundary"     'DL-231é'         'chore/x' 1 "$GATED"
case_gate "boundary: ы before a CVE id is NOT a boundary"      'ыCVE-2026-12143' 'chore/x' 1 "$GATED"
# ⇒ AND THE OTHER DIRECTION: an ASCII boundary next to non-ASCII TEXT still correlates, which
# is what keeps this from being a gate that refuses every non-English title.
case_gate "boundary: a CJK title with an ASCII-spaced token correlates" \
  'エクスポータの修正 card-42 対応' 'chore/x' 0 "$CORRELATING"
case_gate "boundary: a CJK title with a spaced DL token correlates" \
  '修正: DL-231 のエクスポート' 'chore/x' 0 "$CORRELATING"

echo
echo "── §L limb 1 — traceability arrives by another route"
case_gate "limb 1: the bot author is exempt" 'anything' 'chore/x' 0 "limb 1" "$TMP/code.json" 'dependabot[bot]'
case_gate "limb 1: a sync/ branch is exempt" 'anything' 'sync/main-to-dev' 0 "limb 1"
case_gate "limb 1: a release/ branch is exempt" 'anything' 'release/v1.2.0' 0 "limb 1"
case_gate "limb 1: an ordinary branch is NOT exempt" 'anything' 'chore/x' 1 "$GATED"
# An attacker-controlled login must never be read as a PATTERN.
case_gate "limb 1: an author named '*' does not match the automation list" 'anything' 'chore/x' 1 "$GATED" "$TMP/code.json" '*'

echo
echo "── §D limb 2 — the docs-only exemption, and every edge that decides it"

docs_json()  { printf '%s\n' "$1" > "$TMP/f.json"; echo "$TMP/f.json"; }

case_gate "limb 2: an all-.md diff is exempt" 'Correct the guide' 'chore/tidy' 0 "$EXEMPT2" \
  "$(docs_json '[{"filename":"docs/GUIDE.md"},{"filename":"README.md"},{"filename":"a/b/NOTES.md"}]')"
case_gate "limb 2: docs/ assets count as documentation" 'Add the diagram' 'chore/diagram' 0 "$EXEMPT2" \
  "$(docs_json '[{"filename":"docs/arch.png"},{"filename":"docs/openapi.yaml"},{"filename":"docs/x.pdf"}]')"
case_gate "limb 2: MIXED docs+code is NOT exempt" 'Tweak' 'chore/tweak' 1 "$GATED" \
  "$(docs_json '[{"filename":"docs/GUIDE.md"},{"filename":"app/Service.php"}]')"
case_gate "limb 2: an asset OUTSIDE docs/ is NOT documentation" 'Favicon' 'chore/ico' 1 "$GATED" \
  "$(docs_json '[{"filename":"public/favicon.png"}]')"
case_gate "limb 2: an unknown extension is NOT documentation (the enumeration fails toward GATING)" \
  'Add a diagram' 'chore/webp' 1 "$GATED" "$(docs_json '[{"filename":"docs/arch.webp"}]')"
case_gate "limb 2: .MD is not assumed to be documentation (case-sensitive)" 'Shout' 'chore/x' 1 "$GATED" \
  "$(docs_json '[{"filename":"docs/GUIDE.MD"}]')"
# RENAMES — the pre-image is part of the predicate's input, and that is the security property.
case_gate "limb 2: a rename OUT OF code is NOT exempt (previous_filename)" 'Move a file' 'chore/mv' 1 "$GATED" \
  "$(docs_json '[{"filename":"docs/Evil.md","previous_filename":"app/Evil.php","status":"renamed"}]')"
case_gate "limb 2: a rename WITHIN docs stays exempt" 'Rename a guide' 'chore/mv' 0 "$EXEMPT2" \
  "$(docs_json '[{"filename":"docs/NEW.md","previous_filename":"docs/OLD.md","status":"renamed"}]')"
case_gate "limb 2: a rename OUT OF docs into code is NOT exempt" 'Move a file' 'chore/mv' 1 "$GATED" \
  "$(docs_json '[{"filename":"app/New.php","previous_filename":"docs/OLD.md","status":"renamed"}]')"
# DELETIONS — the API reports a removed file under `.filename`, so deleting code is code work.
case_gate "limb 2: DELETING a code file is code work" 'Drop the service' 'chore/rm' 1 "$GATED" \
  "$(docs_json '[{"filename":"app/Service.php","status":"removed"}]')"
case_gate "limb 2: deleting only docs stays exempt" 'Drop a guide' 'chore/rm' 0 "$EXEMPT2" \
  "$(docs_json '[{"filename":"docs/OLD.md","status":"removed"}]')"
# ⛔ EMBEDDED NEWLINE — the reason paths are base64-encoded. Raw, this ONE path becomes two
# documentation-looking lines and wins an exemption the real path never earned.
case_gate "limb 2: a path with an embedded NEWLINE cannot split into two doc fragments" \
  'Sneaky' 'chore/x' 1 "$GATED" \
  "$(docs_json '[{"filename":"a.md\nsrc/evil.php\nb.md"}]')"
# FAIL-CLOSED, one row per way of not knowing.
case_gate "limb 2: an EMPTY file list is not a proof of nothing" 'Something' 'chore/x' 1 "$GATED" \
  "$(docs_json '[]')"
STUB_FAIL_FILES=1 case_gate "limb 2: a failed changed-files read declines" 'Something' 'chore/x' 1 "$GATED" \
  "$(docs_json '[{"filename":"docs/A.md"}]')"
STUB_FAIL_PR=1 case_gate "limb 2: a failed head-SHA read declines" 'Something' 'chore/x' 1 "$GATED" \
  "$(docs_json '[{"filename":"docs/A.md"}]')"
# ⛔ THE FILE LIST MUST DESCRIBE THE SHA THIS RUN CERTIFIES.
case_gate "limb 2: a PR that MOVED under the run declines (stale head)" 'Something' 'chore/x' 1 "$GATED" \
  "$(docs_json '[{"filename":"docs/A.md"}]')" 'a-human' 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
# The 3000-file cap: a truncated list cannot prove docs-only even when every path it shows is docs.
jq -n '[range(3000) | {filename: ("docs/f\(.).md")}]' > "$TMP/cap.json"
case_gate "limb 2: at the 3000-file API cap the list cannot be trusted" 'Huge docs sweep' 'chore/huge' 1 "$GATED" \
  "$TMP/cap.json"
# The token arm short-circuits: limb 2 must not be consulted at all when a token is present.
out="$(run_gate 'fix(card-6829): thing' 'chore/x' 'a-human' "$EVENT_SHA" "$TMP/code.json")"
if [ -s "$TMP/args" ]; then
  fail "a tokened PR consulted the API — limb 2 must not run when a token is present. gh args: $(cat "$TMP/args")"
else
  ok "a tokened PR never consults the API (limb 2 is reached only by a PR that would otherwise fail)"
fi

echo
echo "── §M mutation controls: each deletes ONE fail-closed branch from the shipped block and"
echo "   requires the matching leg to flip to GREEN. Without these, a fail-closed leg could be"
echo "   red because the fixture is odd rather than because the shipped logic is closed."

# Each mutant NEUTRALISES A CONDITION rather than deleting a branch body: deleting the body
# leaves a dangling `fi`, and the leg then fails on a SYNTAX ERROR — which looks exactly like
# "still fails, so the guard was not load-bearing" and would have quietly inverted the control's
# meaning. Both M1 and M3 did precisely that on the first run.
mutate() { printf '%s' "$BLOCKS" | sed "$1"; }

# M1 — delete the head-SHA mismatch guard. The 'PR moved' leg must then EXEMPT.
M1="$(mutate '/if \[ -z "$sha_now" \]/c\            if false; then')"
if [ "$M1" = "$BLOCKS" ]; then
  fail "M1 mutated NOTHING — the head-SHA guard's text moved; this control is inert"
else
  out="$(run_gate 'Something' 'chore/x' 'a-human' 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' "$(docs_json '[{"filename":"docs/A.md"}]')" "$M1")"; rc=$?
  if [ "$rc" -eq 0 ]; then ok "M1: deleting the head-SHA guard makes the stale-head leg PASS — that leg's red comes from the shipped guard"
  else fail "M1: with the head-SHA guard deleted the stale-head leg STILL failed (rc=$rc) — the leg is red for some other reason, so it proves nothing. Output: $out"; fi
fi

# M2 — delete the pre-image read from the shipped seam. The rename-out-of-code leg must EXEMPT.
M2="$(mutate 's/, (\.previous_filename \/\/ empty)//')"
if [ "$M2" = "$BLOCKS" ]; then
  fail "M2 mutated NOTHING — the seam's jq expression moved; this control is inert"
else
  out="$(run_gate 'Move a file' 'chore/mv' 'a-human' "$EVENT_SHA" "$(docs_json '[{"filename":"docs/Evil.md","previous_filename":"app/Evil.php","status":"renamed"}]')" "$M2")"; rc=$?
  if [ "$rc" -eq 0 ]; then ok "M2: dropping .previous_filename makes the rename-out-of-code leg PASS — the pre-image read is what closes it"
  else fail "M2: with .previous_filename dropped the rename leg STILL failed (rc=$rc) — the leg proves nothing about the pre-image. Output: $out"; fi
fi

# M3 — delete the 3000-file cap guard. The capped leg must then EXEMPT.
M3="$(mutate '/if \[ "$n" -ge 3000 \]/c\              if false; then')"
if [ "$M3" = "$BLOCKS" ]; then
  fail "M3 mutated NOTHING — the cap guard's text moved; this control is inert"
else
  out="$(run_gate 'Huge docs sweep' 'chore/huge' 'a-human' "$EVENT_SHA" "$TMP/cap.json" "$M3")"; rc=$?
  if [ "$rc" -eq 0 ]; then ok "M3: deleting the cap guard makes the 3000-file leg PASS — the cap is what closes it"
  else fail "M3: with the cap guard deleted the 3000-file leg STILL failed (rc=$rc). Output: $out"; fi
fi

# M4 — delete the newline REJECTION. The crafted path must then be falsely EXEMPTED, which is
# the measured behaviour the rejection exists to refuse: `[[ =~ ]]` judges the decoded path as
# ONE string, so `$` is end-of-STRING and a path whose LAST line ends `.md` satisfies `\.md$`
# while every line before it is code. This fixture carries a decoy in front of its `b.md` tail;
# M4b below is the same mechanism with nothing but code in front of the `.md`.
M4="$(mutate '/a changed path contains a newline/,+1d')"
if [ "$M4" = "$BLOCKS" ]; then
  fail "M4 mutated NOTHING — the newline-rejection text moved; this control is inert"
else
  out="$(run_gate 'Sneaky' 'chore/x' 'a-human' "$EVENT_SHA" "$(docs_json '[{"filename":"a.md\nsrc/evil.php\nb.md"}]')" "$M4")"; rc=$?
  if [ "$rc" -eq 0 ] && has "$out" "$EXEMPT2"; then
    ok "M4: without the newline rejection the crafted path IS falsely exempted — the rejection is what closes it"
  else
    fail "M4: without the newline rejection the crafted path was still gated (rc=$rc), so the rejection is not what closes that leg. Output: $out"
  fi
fi

# M4b — the shape the newline rejection actually exists for, isolated: a SINGLE crafted path
# whose only documentation-looking part is its LAST line. Two runs, because "the case guard
# catches it" and "the anchor does not" are two claims:
#   (i)  SHIPPED (case guard present)  -> GATED. The guard is what catches it.
#   (ii) M4-mutated (guard deleted, so `[[ "$p" =~ $DOC_RE ]]` is the only thing left) -> falsely
#        EXEMPT. The anchor alone does not catch it, because `$` matches at end-of-string and the
#        string ends `docs/guide.md`.
CRAFTED_TAIL_MD='[{"filename":"src/evil.php\ndocs/guide.md"}]'
out="$(run_gate 'Sneaky tail' 'chore/x' 'a-human' "$EVENT_SHA" "$(docs_json "$CRAFTED_TAIL_MD")")"; rc=$?
if [ "$rc" -eq 1 ] && has "$out" "$GATED"; then
  ok "M4b (i): a path whose LAST line is .md and whose first line is code is GATED by the shipped case guard"
else
  fail "M4b (i): the crafted tail-.md path was NOT gated (rc=$rc) — the newline rejection is not reaching it. Output: $out"
fi
if [ "$M4" = "$BLOCKS" ]; then
  fail "M4b (ii) inert — the newline-rejection text moved, so M4's mutation did nothing"
else
  out="$(run_gate 'Sneaky tail' 'chore/x' 'a-human' "$EVENT_SHA" "$(docs_json "$CRAFTED_TAIL_MD")" "$M4")"; rc=$?
  if [ "$rc" -eq 0 ] && has "$out" "$EXEMPT2"; then
    ok "M4b (ii): with the case guard deleted the SAME path is falsely EXEMPTED — the \`.md\$\` anchor is not what closes this, the case guard is"
  else
    fail "M4b (ii): with the case guard deleted the crafted path was still gated (rc=$rc), so this leg does not show what closes it. Output: $out"
  fi
fi

echo
echo "── §B SEEN TO FAIL, AND SEEN TO PASS. Both directions, with the gate's ACTUAL output"
echo "   printed, because a check that refuses everything also reds when it should."

echo
echo "  DIRECTION 1 — a token-less CODE PR must BLOCK:"
out="$(run_gate 'Refactor the exporter' 'chore/refactor' 'a-human' "$EVENT_SHA" "$TMP/code.json")"; rc=$?
printf '%s\n' "$out" | sed 's/^/    | /'
echo "    | exit=$rc"
if [ "$rc" -eq 1 ] && has "$out" "$GATED"; then
  ok "DIRECTION 1: the gate BLOCKS a token-less code PR (exit 1, ::error emitted)"
else
  fail "DIRECTION 1: the gate did NOT block a token-less code PR (exit $rc)"
fi

echo
echo "  DIRECTION 2 — a docs-only PR with no token at all must PASS:"
out="$(run_gate 'Correct the deployment guide' 'chore/tidy-docs' 'a-human' "$EVENT_SHA" "$(docs_json '[{"filename":"docs/DEPLOY.md"},{"filename":"README.md"}]')")"; rc=$?
printf '%s\n' "$out" | sed 's/^/    | /'
echo "    | exit=$rc"
if [ "$rc" -eq 0 ] && has "$out" "$EXEMPT2"; then
  ok "DIRECTION 2: the gate PASSES a docs-only PR carrying no token (exit 0, limb 2)"
else
  fail "DIRECTION 2: the gate did NOT pass a docs-only PR (exit $rc) — a check that refuses everything is a decoration"
fi

echo
echo "  DIRECTION 2b — a CODE PR that DOES carry a token must PASS:"
out="$(run_gate 'fix(card-6829): rework the exporter' 'fix/card-6829-exporter' 'a-human' "$EVENT_SHA" "$TMP/code.json")"; rc=$?
printf '%s\n' "$out" | sed 's/^/    | /'
echo "    | exit=$rc"
if [ "$rc" -eq 0 ] && has "$out" "$CORRELATING"; then
  ok "DIRECTION 2b: the gate PASSES a tokened code PR (exit 0, correlating token)"
else
  fail "DIRECTION 2b: the gate did NOT pass a tokened code PR (exit $rc)"
fi

finish

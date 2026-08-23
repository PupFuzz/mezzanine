#!/usr/bin/env bash
# promote-cards-by-token.selftest.sh — hermetic, network-free acceptance for
# bin/promote-cards-by-token.
#
# ⚑ VENDORED, NOT AUTHORED HERE — same provenance, same rule, as the script it tests:
# everything from `set -uo pipefail` down is a BYTE-FOR-BYTE copy from
# PupFuzz/agent-board-framework `bin/promote-cards-by-token.selftest.sh` at commit
# e2f131f796baa93a5aa9cec620969bcaa21ac7fe. Fix defects upstream and re-vendor. Paths and
# `card#NNNN` references inside the body resolve in THAT repo, not this one.
#
# WHY IT SHIPS HERE rather than being left upstream. The mover writes to a TERMINAL board
# stage on every release, and this repo's CI runs the mover, not upstream's. Two failure
# directions are both unacceptable and both LOOK like a green run:
#   - a false promotion drags a backlog/declined card into a terminal stage, and
#   - a silent no-op leaves a shipped card stranded forever.
# So every guard is exercised against a REAL fixture through the REAL script with `curl`
# stubbed on PATH and a real git-fixture repo for the range derivation — no assertion on an
# exit code alone, and no live board write. It needs no kanban credential and no network,
# which is exactly why it can run on a public runner.
#
# RED-FIRST EVIDENCE. Each block upstream names the mutation that turns it red. A pass is
# evidence only if failure was possible.
#
# ⚠ THE BOARD AND STAGE IDS IN THE FIXTURES BELOW (board 13, stages 93/97) ARE NOT THIS
# REPO'S. They are upstream's, and they stay upstream's for two reasons: keeping them makes
# the body a one-diff check against upstream, and a fixture that used board 14's real ids
# would read as though this suite writes to the live board. It writes to nothing — `curl` is
# a stub on PATH and the git history is a throwaway fixture repo. This repo's real ids live
# in `.release-pr.json`, which this file never reads.
#
# The grammar matrix at the end EXTRACTS both regexes from the mover (never restating them),
# so it can only ever pin the constants the mover actually uses — which is also what makes
# `bin/card-token-lint.py`'s run-time extraction of the same two lines safe to rely on.
set -uo pipefail

HERE="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
PRC="$HERE/promote-cards-by-token"
[ -x "$PRC" ] || { echo "selftest: $PRC not found or not executable" >&2; exit 1; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
export HOME="$TMP/home"; mkdir -p "$HOME"

fails=0
ok()  { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1" >&2; fails=$((fails + 1)); }
eq()  { [ "$2" = "$3" ] && ok "$1" || bad "$1 — expected '$2' got '$3'"; }
# has <needle> <haystack> — literal substring test (robust to JSON/emoji punctuation).
has() { case "$2" in *"$1"*) echo true ;; *) echo false ;; esac; }

# ── fake curl on PATH ────────────────────────────────────────────────────────────────
# Serves canned per-resource responses out of $FIX and records every call. Mirrors the
# real api() contract: writes the body to the `-o` file and prints the status code.
mkdir -p "$TMP/bin" "$TMP/fix"
FIX="$TMP/fix"; export FIX
export CALL_LOG="$TMP/calls.log" PATCH_LOG="$TMP/patches.log"
cat > "$TMP/bin/curl" <<'STUB'
#!/usr/bin/env bash
method=GET; url=""; out=""; data=""; want=""
for a in "$@"; do
  case "$want" in
    X) method="$a"; want=""; continue ;;
    o) out="$a"; want=""; continue ;;
    d) data="$a"; want=""; continue ;;
  esac
  case "$a" in
    -X) want=X ;;
    -o) want=o ;;
    -d|--data-binary) want=d ;;
    http://*|https://*) url="$a" ;;
  esac
done
printf '%s\t%s\n' "$method" "$url" >> "$CALL_LOG"
if [ "$method" = PATCH ]; then
  printf '%s\t%s\n' "$url" "$data" >> "$PATCH_LOG"
  [ -n "$out" ] && printf '{"data":{"id":0}}' > "$out"
  # per-card override first ($FIX/patch.<id>.code), then the blanket $FIX/patch.code.
  pid="${url##*/tasks/}"; pid="${pid%%.json*}"
  printf '%s' "$(cat "$FIX/patch.$pid.code" 2>/dev/null || cat "$FIX/patch.code" 2>/dev/null || echo 200)"
  exit 0
fi
case "$url" in
  # The PAGED whole-board read (the stranded-card residue report) carries `page=N`; the
  # preflight's one-row visibility probe does not. They must serve DIFFERENT fixtures or a
  # residue case could not distinguish "the board has one visible card" from "page 1 of the
  # board", and every residue assertion would be reading the preflight's body.
  *"/tasks/search.json"*"page="*) key="board.page${url##*page=}" ;;
  *"/tasks/search.json"*)         key=search ;;
  *"/tasks/"*)            key="${url##*/tasks/}"; key="${key%%.json*}" ;;
  *)                      key=unknown ;;
esac
[ -n "$out" ] && { [ -f "$FIX/$key.body" ] && cat "$FIX/$key.body" > "$out" || : > "$out"; }
printf '%s' "$(cat "$FIX/$key.code" 2>/dev/null || echo 404)"
STUB
chmod +x "$TMP/bin/curl"
export PATH="$TMP/bin:$PATH"

# card <id> <board_id> <stage> [archived_at] — install a GET /tasks/<id>.json fixture.
card() {
  printf '200' > "$FIX/$1.code"
  printf '{"data":{"id":%s,"board_id":%s,"workflow_stage_id":%s,"archived_at":%s,"deleted_at":null}}\n' \
    "$1" "$2" "$3" "$([ -n "${4:-}" ] && printf '"%s"' "$4" || printf 'null')" > "$FIX/$1.body"
}
# board_page <n> <cards-json-array> [meta.total] [meta.last_page] — install page <n> of the
# PAGED whole-board read the stranded-card residue report performs. Omitted meta fields are
# served as null, which is the "server declares nothing" shape the census must tolerate.
board_page() {
  printf '200' > "$FIX/board.page$1.code"
  printf '{"data":%s,"meta":{"total":%s,"last_page":%s}}\n' "$2" "${3:-null}" "${4:-null}" \
    > "$FIX/board.page$1.body"
}
# reset_fixtures — a visible board and a clean call log.
# The default whole-board page is ONE already-released card: every case that is not about the
# residue then has an empty Shipped-class residue and stays quiet, so the report cannot make a
# pre-existing case pass or fail for a reason it never meant to assert.
reset_fixtures() {
  rm -f "$FIX"/*.code "$FIX"/*.body
  printf '200' > "$FIX/search.code"
  printf '{"data":[{"id":1}],"meta":{"total":132}}' > "$FIX/search.body"
  board_page 1 '[{"id":1,"board_id":13,"workflow_stage_id":93,"archived_at":null,"deleted_at":null}]' 1 1
  : > "$CALL_LOG"; : > "$PATCH_LOG"
}

# ── config: board 13, released 93 (terminal), shipped-class 97 ───────────────────────
CFG="$TMP/release-pr.json"
cat > "$CFG" <<'JSON'
{
  "tag_format": "v{{version}}",
  "promote": {
    "board_id": 13,
    "released_stage_id": 93,
    "shipped_stage_ids": "97",
    "api_base": "https://kanban.test/api/v3"
  }
}
JSON

export KANBAN_WRITEBACK_TOKEN=tkn
export KANBAN_EXPECTED_HOST=kanban.test

# ── git fixture: a MERGE-commit release tip over two card-token squash commits ───────
# HOME is a scratch dir, so there is NO global git identity here: set one EXPLICITLY on
# each fixture repo. `git merge` needs it just as much as `git commit` does, and a fixture
# that fails to build must fail LOUDLY — a half-built repo silently turns every range
# assertion below into a meaningless refusal.
GFX="$TMP/gitfx"; mkdir -p "$GFX"
gitfx_init() {
  git -C "$1" init -q -b main
  git -C "$1" config user.email t@t
  git -C "$1" config user.name t
}
gitfx_init "$GFX"
gc() { git -C "$GFX" commit -q --allow-empty -m "$1" || { echo "selftest: fixture commit failed: $1" >&2; exit 1; }; }
gc "baseline"
git -C "$GFX" tag v0.0.1
BASE_SHA="$(git -C "$GFX" rev-parse HEAD)"
git -C "$GFX" checkout -q -b rel
gc "feat: do a thing (card#101, roundtable #9) (#7)"
gc "docs: adjust the thing (card#102) (#8)"
gc "chore: mention card4 in prose (#10)"       # near-miss: must WARN, must not correlate
                                               # (single-digit glued — below the DL-233
                                               #  two-digit floor; `card123` CORRELATES)
gc "release: bump to 0.0.2 + CHANGELOG entry"
git -C "$GFX" checkout -q main
git -C "$GFX" merge -q --no-ff -m "Merge pull request #11 from PupFuzz/release/v0.0.2" rel \
  || { echo "selftest: fixture merge failed — the range fixtures would be meaningless" >&2; exit 1; }
git -C "$GFX" tag v0.0.2
MERGE_SHA="$(git -C "$GFX" rev-parse HEAD)"
# Fixture sanity: the tip MUST be a merge commit, or cases 10/14 assert nothing real.
[ "$(git -C "$GFX" rev-list --parents -n1 HEAD | wc -w)" -ge 3 ] \
  || { echo "selftest: fixture tip is not a merge commit — fixture build is broken" >&2; exit 1; }

# A SQUASHED release tip on its own branch: one non-merge commit naming no card.
git -C "$GFX" checkout -q -b squashed v0.0.1
gc "release: v0.0.3 (squashed)"
SQUASH_SHA="$(git -C "$GFX" rev-parse HEAD)"
# A squashed tip whose range STILL CARRIES a card token — the shape that used to exit 0.
# The incomplete-derivation guard was nested inside `if moved == 0`, so one surviving token
# suppressed it entirely; case 10 pins that it now fires on the tip, not on the count.
git -C "$GFX" checkout -q -b squashed-with-token v0.0.1
gc "fix: hotfix that reached main (card#101) (#12)"
gc "release: v0.0.3 (squashed — the release commit names no card)"
SQUASH_TOK_SHA="$(git -C "$GFX" rev-parse HEAD)"
git -C "$GFX" checkout -q main
# Fixture sanity: BOTH squash tips must be non-merge, or case 10 asserts nothing real.
for s in "$SQUASH_SHA" "$SQUASH_TOK_SHA"; do
  [ "$(git -C "$GFX" rev-list --parents -n1 "$s" | wc -w)" -eq 2 ] \
    || { echo "selftest: squash fixture tip $s is a merge commit — fixture build is broken" >&2; exit 1; }
done

# ── git fixture 2: the push-`before` sha and the previous release tag DIVERGE ─────────
# THE POINT OF CASE 14a. In $GFX the tagged baseline IS the commit `git describe HEAD^`
# resolves to, so both derivation paths produce the SAME range and no assertion there can
# tell them apart — the original 14a stayed green even when the mover verified the `before`
# sha and then THREW IT AWAY (proved by mutation: `BASE="$gb"` → `:` left the whole suite
# green). Here the two bases are deliberately different commits:
#
#   baseline (tag v0.0.1) ── C104 "feat: … (card#104)" ── M2 (merge bringing card#103)  ← head
#                                    ▲ push-`before`
#
#   push-`before` honored              ⇒ range C104..M2   ⇒ ONLY card#103 promoted
#   tag fallback (describe M2^ = v0.0.1) ⇒ range v0.0.1..M2 ⇒ card#103 AND card#104
#
# so the two bases are distinguishable by WHICH CARDS MOVE. The tip is a MERGE commit so the
# case exercises base selection alone, not the incomplete-derivation exit.
GFX2="$TMP/gitfx2"; mkdir -p "$GFX2"
gitfx_init "$GFX2"
gc2() { git -C "$GFX2" commit -q --allow-empty -m "$1" || { echo "selftest: fixture-2 commit failed: $1" >&2; exit 1; }; }
gc2 "baseline"
git -C "$GFX2" tag v0.0.1
gc2 "feat: an earlier push straight to main (card#104) (#12)"
BEFORE2_SHA="$(git -C "$GFX2" rev-parse HEAD)"
git -C "$GFX2" checkout -q -b rel2
gc2 "feat: the thing THIS push released (card#103) (#13)"
git -C "$GFX2" checkout -q main
git -C "$GFX2" merge -q --no-ff -m "Merge pull request #14 from PupFuzz/release/v0.0.2" rel2 \
  || { echo "selftest: fixture-2 merge failed — case 14a would be meaningless" >&2; exit 1; }
# Fixture-2 sanity — the whole value of 14a is that the two bases DIFFER. Assert it
# structurally, or a future edit could quietly collapse them and re-vacuify the case.
[ "$(git -C "$GFX2" describe --tags --abbrev=0 --match 'v*' HEAD^)" = "v0.0.1" ] \
  && [ "$(git -C "$GFX2" rev-parse v0.0.1)" != "$BEFORE2_SHA" ] \
  || { echo "selftest: fixture-2's push-before sha and tag fallback COINCIDE — case 14a would prove nothing" >&2; exit 1; }
[ "$(git -C "$GFX2" rev-list --parents -n1 HEAD | wc -w)" -ge 3 ] \
  || { echo "selftest: fixture-2 tip is not a merge commit — fixture build is broken" >&2; exit 1; }

# run <args...> — invoke the REAL mover from the git fixture; capture rc/out/err/patches.
# $GITFX selects WHICH fixture repo. Case 14a points it at $GFX2 and restores it; a forgotten
# restore is not silent — case 17's near-miss subject exists only in $GFX, so it goes red.
GITFX="$GFX"
run() {
  rc=0
  out="$(cd "$GITFX" && "$PRC" --config "$CFG" "$@" 2>"$TMP/err")" || rc=$?
  err="$(cat "$TMP/err")"
  patched="$(cat "$PATCH_LOG")"
  calls="$(cat "$CALL_LOG")"
}

# run_with <VAR> <VALUE> [args...] — run() with one env var temporarily overridden, then
# restored. NOT a subshell: run() must publish rc/out/err/patched into THIS shell (a
# `( export X=…; run )` silently discards them, so every following assertion would read
# the PREVIOUS case's result and pass vacuously).
run_with() {
  local var="$1" val="$2"; shift 2
  local had=0 old=""
  [ -n "${!var+x}" ] && { had=1; old="${!var}"; }
  export "$var=$val"
  run "$@"
  if [ "$had" = 1 ]; then export "$var=$old"; else unset "$var"; fi
}

echo "== 1. ANTI-RESURRECTION: a matched card outside a Shipped-class stage is SKIPPED =="
# RED when: the `in_shipped_stage` guard in the move loop is removed → card#102 (Backlog 91)
# is PATCHed into the terminal stage and both assertions below flip.
reset_fixtures
card 101 13 97            # Shipped to dev → promotable
card 102 13 91            # Backlog       → must NOT move
run
eq "rc 0"                                          "0"     "$rc"
eq "shipped card#101 PATCHed"                      "true"  "$(has '/tasks/101.json' "$patched")"
eq "backlog card#102 NOT PATCHed"                  "false" "$(has '/tasks/102.json' "$patched")"
eq "skip names the card"                           "true"  "$(has 'card#102' "$err")"
eq "skip names its current stage"                  "true"  "$(has 'stage 91' "$err")"
eq "skip explains the anti-resurrection reason"    "true"  "$(has 'never resurrects' "$err")"
eq "summary counts the stage-guarded skip"         "true"  "$(has '1 stage-guarded' "$out")"

echo "== 2. FLAT write contract + the move actually lands =="
# RED when: the PATCH body is changed to the {"task":{...}} resource wrapper (which kanban
# v0.36.0 / DL-219 strict-rejects) → both assertions flip.
# EXACT-equality, not a substring: `{"task":{"workflow_stage_id":93}}` CONTAINS the flat
# form, so a `has` check here would stay green against the very wrapper it must reject.
eq "PATCH body is EXACTLY the flat {workflow_stage_id}" '{"workflow_stage_id":93}' \
   "$(printf '%s\n' "$patched" | awk -F'\t' '$1 ~ /\/tasks\/101\.json$/ {print $2}')"
eq "PATCH body carries NO 'task' wrapper"          "false" "$(has '"task"' "$patched")"

echo "== 3. Won't-Do card carrying a matched token is also guarded (sibling of case 1) =="
reset_fixtures
card 101 13 98            # Won't Do
card 102 13 98
run
eq "nothing PATCHed"                               ""      "$patched"
eq "0 moved, complete derivation → rc 0"           "0"     "$rc"
eq "0-promoted run still WARNs"                    "true"  "$(has '0 cards newly promoted' "$err")"

echo "== 4. IDEMPOTENT: a re-run over the same range writes nothing and exits 0 =="
# RED when: the `[ "$cur" = "$STAGE" ]` already-released check is dropped → the cards are
# re-PATCHed and `patched` is non-empty.
reset_fixtures
card 101 13 93            # already Released
card 102 13 93
run
eq "re-run rc 0"                                   "0"     "$rc"
eq "re-run performs ZERO writes"                   ""      "$patched"
eq "re-run reports already-released"               "true"  "$(has '2 already-released' "$out")"
# The already-released check must be evaluated BEFORE the stage guard, or an idempotent
# re-run would read as a stage-guarded skip — semantically wrong (nothing was guarded).
eq "re-run guards nothing (order: released check first)" "true" "$(has '0 stage-guarded' "$out")"

echo "== 5. A token that matches NO card on the board is reported, not ignored =="
# RED when: the 404 arm stops counting `unmatched` (or exits 0) → rc drops to 0.
reset_fixtures
card 101 13 97
printf '404' > "$FIX/102.code"
run
eq "unresolved token → rc 3 (distinct from a refusal)" "3" "$rc"
eq "the resolvable card still moved"               "true"  "$(has '/tasks/101.json' "$patched")"
eq "the unresolved token is named"                 "true"  "$(has 'card#102' "$err")"
eq "the report offers the archived explanation"    "true"  "$(has 'archived' "$err")"

echo "== 6. BOARD-SCOPE: a token resolving to ANOTHER board's card is rejected =="
# RED when: the `[ "$cbd" != "$BOARD" ]` guard is removed → card#102 (board 3) is PATCHed.
reset_fixtures
card 101 13 97
card 102 3 97             # a sola board-3 card that happens to share the id space
run
eq "out-of-board token → rc 3"                     "3"     "$rc"
eq "out-of-board card NOT PATCHed"                 "false" "$(has '/tasks/102.json' "$patched")"
eq "rejection names the wrong board"               "true"  "$(has 'on board 3, not board 13' "$err")"

echo "== 7. An ARCHIVED card is reported, never moved =="
reset_fixtures
card 101 13 97
card 102 13 97 "2026-07-01T00:00:00+00:00"
run
eq "archived token → rc 3"                         "3"     "$rc"
eq "archived card NOT PATCHed"                     "false" "$(has '/tasks/102.json' "$patched")"
eq "report says archived/deleted"                  "true"  "$(has 'archived/deleted' "$err")"

echo "== 8. A write FAILURE is loud and non-zero, even when other cards moved =="
# This is deliberately a PARTIAL failure — card#101 moves, card#102's write errors. A
# blanket failure would pass under promote-released-cards' `failed>0 && moved==0 &&
# skipped==0` conjunction too, so it would prove nothing about the divergence.
# RED when: the exit policy adopts that conjunction → this run exits 0 with one shipped
# card silently un-promoted, which is exactly the silent-green being closed.
reset_fixtures
card 101 13 97
card 102 13 97
printf '500' > "$FIX/patch.102.code"
run
eq "partial write failure → rc 1"                  "1"     "$rc"
eq "  … the healthy card still moved"              "true"  "$(has '/tasks/101.json' "$patched")"
eq "failure names the http status"                 "true"  "$(has 'HTTP 500' "$err")"
eq "failure says the cards are un-promoted"        "true"  "$(has 'still un-promoted' "$err")"

echo "== 9. A degraded (non-404) card read REFUSES rather than promoting a partial set =="
reset_fixtures
card 101 13 97
printf '503' > "$FIX/102.code"
run
eq "degraded read → rc 2"                          "2"     "$rc"
eq "refusal names the degraded read"               "true"  "$(has 'degraded board read' "$err")"

echo "== 10. A range with NO card token: merge tip = nothing to do; squash tip = FAIL =="
# RED when: the merge_commit_tip discriminator is dropped from the empty-token branch →
# the squash case silently exits 0, which is exactly the v0.32.0 silent-green class.
reset_fixtures
run --base "$MERGE_SHA" --head "$MERGE_SHA"
eq "empty range, merge tip → rc 0"                 "0"     "$rc"
eq "empty range → nothing to do"                   "true"  "$(has 'nothing to do' "$out")"
# An empty token set is the MAXIMAL-residue case, so the stranded-card report runs here too
# (card#5128) — this deliberately replaces the older "no board calls at all" assertion, which
# pinned an INCIDENTAL property of the nothing-to-do path rather than a safety one. The
# safety invariant is unchanged and asserted directly: no writes, and no per-card reads.
eq "empty range → ZERO writes"                     ""      "$patched"
eq "empty range → every board call is the READ-ONLY residue page" \
                                                   "0"     "$(printf '%s\n' "$calls" | grep -vc 'search\.json')"
eq "empty range → the residue report still measured the board" "true" "$(has 'page=1' "$calls")"
reset_fixtures
run --base v0.0.1 --head "$SQUASH_SHA"
eq "no tokens + squash tip → rc 4"                 "4"     "$rc"
eq "the die names the squash cause"                "true"  "$(has 'NOT a merge commit' "$err")"
# THE CASE THE `moved == 0` GATING HID. A squash/rebase tip whose range still carries ONE
# card token: the token resolves, the card moves — and the incomplete-derivation guard used
# to be skipped entirely because it was nested inside `if moved == 0`, so the run exited 0
# with no warning at all while every collapsed subject's card stayed stranded forever.
# RED when: the guard is put back under `if [ "$moved" = 0 ]` → rc 0 and an EMPTY stderr.
reset_fixtures
card 101 13 97
run --base v0.0.1 --head "$SQUASH_TOK_SHA"
eq "squash tip + a SURVIVING token → rc 4"         "4"     "$rc"
eq "  … the token it did find is still promoted"   "true"  "$(has '/tasks/101.json' "$patched")"
eq "  … and the tip is still called out"           "true"  "$(has 'NOT a merge commit' "$err")"
eq "  … naming the stranded-card risk"             "true"  "$(has 'silently un-promoted' "$err")"
# Two degraded states at once: EVERY one is reported, and the exit code is the most severe.
# An exit taken inside the first matching branch is the same suppression shape as the bug
# above, one level up — so the report is cumulative and only the LAST step picks the code.
# RED when: any of the summary branches exits inside itself again → whichever condition is
# checked first wins and the other's message never reaches the operator.
reset_fixtures
card 101 13 97
printf '500' > "$FIX/patch.101.code"
run --base v0.0.1 --head "$SQUASH_TOK_SHA"
eq "squash tip + failed write → rc 1 (most severe)" "1"    "$rc"
eq "  … the write failure is reported"             "true"  "$(has 'card move(s) errored' "$err")"
eq "  … AND the squash tip is reported too"        "true"  "$(has 'NOT a merge commit' "$err")"

echo "== 11. CREDENTIALS: absent token / absent expected-host fail closed, pre-network =="
# RED when: either guard gains a default → rc 0/other, and `calls` becomes non-empty
# (the token would already have left the runner).
reset_fixtures
card 101 13 97; card 102 13 97
run_with KANBAN_WRITEBACK_TOKEN ''
eq "no writeback token → rc 2"                     "2"     "$rc"
eq "no writeback token → says which secret"        "true"  "$(has 'KANBAN_WRITEBACK_TOKEN is not set' "$err")"
eq "no writeback token → ZERO network calls"       ""      "$(cat "$CALL_LOG")"
: > "$CALL_LOG"
run_with KANBAN_EXPECTED_HOST ''
eq "no expected-host → rc 2"                       "2"     "$rc"
eq "no expected-host → refuses to send"            "true"  "$(has 'refusing to send' "$err")"
eq "no expected-host → ZERO network calls"         ""      "$(cat "$CALL_LOG")"

echo "== 12. HOST GUARD: the token is never sent to an unexpected host =="
# RED when: host_ok's authority terminator narrows from [/?#] to / — then
# `https://evil.test#@kanban.test/…` parses as kanban.test (the userinfo strip reaches
# into the FRAGMENT) while curl sends to evil.test, and the token leaks. Also RED when the
# subdomain arm loosens from `*.$EXPECT_HOST` to a substring match — then
# kanban.test.evil.test is accepted.
for bad_base in "https://evil.test/api/v3" "https://kanban.test@evil.test/api/v3" \
                "https://evil.test#@kanban.test/api/v3" \
                "https://kanban.test.evil.test/api/v3" "http://kanban.test/api/v3"; do
  : > "$CALL_LOG"
  run_with KANBAN_API_BASE "$bad_base"
  eq "refuses api_base $bad_base (rc 2)"           "2"     "$rc"
  eq "  … and sends nothing"                       ""      "$(cat "$CALL_LOG")"
done
reset_fixtures
card 101 13 97; card 102 13 97
run_with KANBAN_API_BASE "https://sub.kanban.test/api/v3"
eq "accepts a subdomain of the expected host"      "0"     "$rc"

echo "== 13. BLIND BOARD READ: 0 visible cards is a refusal, not 'nothing matched' =="
# RED when: the preflight's nvis>0 check is removed → the run proceeds and every per-id
# read 404s, reporting 'archived' for a card that is really just invisible to the token.
reset_fixtures
card 101 13 97; card 102 13 97
printf '{"data":[],"meta":{"total":0}}' > "$FIX/search.body"
run
eq "0 visible cards → rc 2"                        "2"     "$rc"
eq "refusal blames board membership"               "true"  "$(has 'not a member of board 13' "$err")"
eq "refusal happens BEFORE any card read"          "false" "$(has '/tasks/101.json' "$calls")"
reset_fixtures
printf '401' > "$FIX/search.code"
card 101 13 97; card 102 13 97
run
eq "preflight non-2xx → rc 2"                      "2"     "$rc"
eq "preflight failure names the status"            "true"  "$(has 'HTTP 401' "$err")"

echo "== 14. RANGE DERIVATION =="
# 14a. GITHUB_EVENT_BEFORE is the base when present — the headline design decision of this
# tool (a MEASURED base beats both inferred ones), so it is asserted on $GFX2, where the
# push-`before` sha and the tag fallback resolve to DIFFERENT commits and therefore to
# different card sets. On $GFX they coincide, which is why the old assertion here could not
# fail: verifying `before` and then discarding it (`BASE="$gb"` → `:`) left the suite green.
# RED when: `BASE="$gb"` is dropped/discarded → the tag fallback runs, card#104 moves too.
GITFX="$GFX2"                     # ← the diverging fixture; restored at the end of 14b
reset_fixtures
card 103 13 97; card 104 13 97
run_with GITHUB_EVENT_BEFORE "$BEFORE2_SHA"
eq "push-event before → rc 0"                      "0"     "$rc"
eq "push-event before → the pushed card#103 moved" "true"  "$(has '/tasks/103.json' "$patched")"
eq "push-event before → pre-'before' card#104 NOT moved (the tag fallback WOULD have)" \
                                                   "false" "$(has '/tasks/104.json' "$patched")"
eq "push-event before → EXACTLY one card promoted" "1"     "$(printf '%s\n' "$patched" | grep -c '/tasks/')"
# 14b. the all-zero sha (branch creation) is NOT a base — fall through to the release tag.
# Asserted on the SAME fixture, which is what proves the two ranges genuinely differ here:
# the fallback promotes BOTH cards where 14a promoted one. Without this pair, a future edit
# that re-collapsed the fixture would make 14a silently vacuous again.
reset_fixtures
card 103 13 97; card 104 13 97
run_with GITHUB_EVENT_BEFORE "0000000000000000000000000000000000000000"
eq "null before → falls back to the tag → rc 0"    "0"     "$rc"
eq "null before → the WIDER tag range promotes both" "2"   "$(printf '%s\n' "$patched" | grep -c '/tasks/')"
GITFX="$GFX"                      # ← restore: every case below uses the primary fixture
# 14c. an UNREACHABLE before sha refuses; it never silently falls back to a wider range.
# RED when: the rev-parse verification is dropped → git log dies (or the range widens).
reset_fixtures
run_with GITHUB_EVENT_BEFORE "deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
eq "unreachable before → rc 2"                     "2"     "$rc"
eq "refusal names the force-push/shallow cause"    "true"  "$(has 'force-push' "$err")"
# 14d. a before sha that EXISTS but is NOT AN ANCESTOR of the head (a force-push that
# rewrote the branch, whose old tip is still present via another ref) is refused too:
# `before..head` would then span the rewrite instead of measuring this push. 14c proves the
# rev-parse guard and passes without this one — EXISTENCE is not ancestry.
# RED when: the `merge-base --is-ancestor` check is dropped → the range resolves off the
# diverged tip and both primary-fixture cards are promoted at rc 0.
reset_fixtures
card 101 13 97; card 102 13 97
run_with GITHUB_EVENT_BEFORE "$SQUASH_SHA"
eq "non-ancestor before → rc 2"                    "2"     "$rc"
eq "refusal says it is NOT an ancestor"            "true"  "$(has 'NOT an ancestor' "$err")"
eq "non-ancestor before → ZERO writes"             ""      "$patched"
# 14e. FIRST-EVER RELEASE (no before, no tag): refuse rather than report a false all-clear.
# NB the consequence of falling through is a SILENT NO-OP, not a mass move: git resolves an
# omitted left side to HEAD, so `..HEAD` is EMPTY. This case pins that — with the die
# removed the run exits 0 saying "nothing to do" while the first release's cards, which
# genuinely shipped, stay un-promoted forever.
NOTAG="$TMP/notag"; mkdir -p "$NOTAG"
gitfx_init "$NOTAG"
git -C "$NOTAG" commit -q --allow-empty -m "feat: first (card#101) (#1)"
reset_fixtures
rc=0; err="$(cd "$NOTAG" && "$PRC" --config "$CFG" 2>&1 >/dev/null)" || rc=$?
eq "first-ever release → rc 2"                     "2"     "$rc"
eq "refusal names the false-all-clear risk"        "true"  "$(has 'false all-clear' "$err")"
eq "refusal offers --base / --cards"               "true"  "$(has '--cards' "$err")"
eq "first-ever release → ZERO network calls"       ""      "$(cat "$CALL_LOG")"

echo "== 15. --cards bypasses derivation and accepts both spellings =="
reset_fixtures
card 101 13 97; card 102 13 97
run --cards "101, card#102"
eq "--cards rc 0"                                  "0"     "$rc"
eq "--cards promoted both"                         "2"     "$(printf '%s\n' "$patched" | grep -c '/tasks/10')"
eq "--cards consults no git range"                 "false" "$(has 'nothing to do' "$out")"

echo "== 16. --dry-run moves nothing =="
reset_fixtures
card 101 13 97; card 102 13 97
run --dry-run
eq "dry-run rc 0"                                  "0"     "$rc"
eq "dry-run performs ZERO writes"                  ""      "$patched"
eq "dry-run says so"                               "true"  "$(has '(dry-run)' "$out")"

echo "== 17. NEAR-MISS tokens WARN instead of vanishing =="
# RED when: the near-miss probe is deleted → the 'card4' subject in the fixture range
# passes silently and this assertion flips.
reset_fixtures
card 101 13 97; card 102 13 97
run
eq "near-miss subject WARNs"                       "true"  "$(has 'near-miss card token' "$err")"
eq "  … and names the offending subject"           "true"  "$(has 'card4' "$err")"
eq "near-miss does NOT correlate a card"           "false" "$(has '/tasks/4.json' "$calls")"

echo "== 18. CONFIG validation fails loud =="
for bad in '{"promote":{"board_id":"x","released_stage_id":93,"shipped_stage_ids":"97","api_base":"https://kanban.test/api/v3"}}' \
           '{"promote":{"board_id":13,"released_stage_id":93,"shipped_stage_ids":"97,foo","api_base":"https://kanban.test/api/v3"}}' \
           '{"promote":{"board_id":13,"released_stage_id":93,"shipped_stage_ids":"93,97","api_base":"https://kanban.test/api/v3"}}' \
           '{"promote":{"board_id":13,"released_stage_id":93,"api_base":"https://kanban.test/api/v3"}}' \
           'not json at all'; do
  printf '%s' "$bad" > "$TMP/bad.json"
  reset_fixtures
  rc=0; err="$(cd "$GFX" && "$PRC" --config "$TMP/bad.json" 2>&1 >/dev/null)" || rc=$?
  eq "bad config → rc 2 [${bad:0:34}…]"            "2"     "$rc"
  eq "  … and sends nothing"                       ""      "$(cat "$CALL_LOG")"
done

echo "== 19. A MISSING option ARGUMENT is a refusal (rc 2), not a bash abort (rc 1) =="
# A value-taking option as the FINAL argument used to hit `set -u` on "$2": bash aborted with
# `$2: unbound variable` and exit 1 — the code this tool documents as "a card write FAILED",
# i.e. a malformed command line was indistinguishable from a half-completed release.
# RED when: the shared arity guard is removed and the arms go back to a bare `X="$2"; shift 2`
# → rc 1 and an 'unbound variable' abort.
for opt in --cards --base --head --config; do
  reset_fixtures
  rc=0; err="$(cd "$GFX" && "$PRC" "$opt" 2>&1 >/dev/null)" || rc=$?
  eq "trailing $opt → rc 2 (refusal, not 1)"       "2"     "$rc"
  eq "  … names the option and its usage"          "true"  "$(has "option '$opt' requires an argument" "$err")"
  eq "  … is not a raw bash unbound-variable abort" "false" "$(has 'unbound variable' "$err")"
  eq "  … and sends nothing"                       ""      "$(cat "$CALL_LOG")"
done

echo "== 20. --help prints the WHOLE header and nothing but the header =="
# A fixed line range gets this wrong in BOTH directions as the file changes, and it already
# had: `sed -n '2,90p'` spilled `set -euo pipefail`, `die()` and two helpers into the help
# output when the header was 82 lines, and TRUNCATES the Env: block now that it is 97. So the
# assertion is a LINE-COUNT equality against the header the file actually has — it pins both
# edges at once and cannot rot the way a hard-coded excerpt would.
# RED when: the stop-at-first-non-comment rule is replaced by any fixed range (too few lines
# ⇒ silently truncated help; too many ⇒ script body leaked into user-facing output).
rc=0; helpout="$("$PRC" --help 2>&1)" || rc=$?
hdr_lines="$(awk 'NR>1 { if (substr($0,1,1) == "#") n++; else exit } END {print n+0}' "$PRC")"
eq "--help rc 0"                                   "0"     "$rc"
eq "--help prints EXACTLY the header comment block" "$hdr_lines" "$(printf '%s\n' "$helpout" | wc -l | tr -d ' ')"
eq "--help shows the usage line"                   "true"  "$(has 'Usage: promote-cards-by-token' "$helpout")"
eq "--help shows the exit table"                   "true"  "$(has 'EXIT CODES' "$helpout")"
eq "--help leaks no script body"                   "false" "$(has 'set -euo pipefail' "$helpout")"

echo "== 21. card-token grammar — accept-set parity + PROVE-TO-FAIL =="
# The accept-set is owned by plugins/coord/docs/BRIDGE-WRITEBACK.md § The `card#<task-id>`
# convention, item 2; this matrix pins what the mover REALLY uses, because both constants are
# EXTRACTED from it rather than restated here.
eval "$(grep -E '^(CARD_RE|NEAR_MISS_RE)=' "$PRC")"
[ -n "${CARD_RE:-}" ] && [ -n "${NEAR_MISS_RE:-}" ] \
  || bad "could not extract CARD_RE/NEAR_MISS_RE from $PRC — were they renamed?"
# The digit extraction reproduces the mover's `numlist` — including the leading-zero strip,
# without which `card007` would read as 007 here and 7 in the mover.
tok()  { printf '%s\n' "$1" | grep -oiE "$CARD_RE" | grep -oE '[0-9]+' \
           | sed 's/^0*\([0-9]\)/\1/' | head -1; }
# near() reproduces the MOVER's gating, not the raw probe: the mover pipes the probe hits
# through `grep -viE "$CARD_RE"`, so a subject that CORRELATES is never warned about. The probe
# pattern is deliberately broader than the miss set (it still matches glued `card123`), so a
# raw-probe version of this helper would assert the opposite of what the mover does.
near() { printf '%s\n' "$1" | grep -qiE "$NEAR_MISS_RE" \
           && ! printf '%s\n' "$1" | grep -qiE "$CARD_RE" && echo true || echo false; }
# The two superseded spellings, kept ONLY to prove the matrix is non-vacuous.
OLD_RE='\bcard#[0-9]+\b'                 # pre-DL-201: hash-only, trailing boundary
PRE233_RE='\bcard[-#][0-9]+'              # pre-DL-233: separated arms only, no glued arm
oldtok()  { printf '%s\n' "$1" | grep -oiE "$OLD_RE" | grep -oE '[0-9]+' \
              | sed 's/^0*\([0-9]\)/\1/' | head -1; }
pre233()  { printf '%s\n' "$1" | grep -oiE "$PRE233_RE" | grep -oE '[0-9]+' \
              | sed 's/^0*\([0-9]\)/\1/' | head -1; }

while IFS='|' read -r text want; do
  [ -n "$text" ] || continue
  eq "correlates '$text' → $want"                  "$want" "$(tok "$text")"
done <<'CASES'
card#123|123
card-123|123
card#3054_fix|3054
card-123-foo|123
Fix widget (Card#12)|12
feature/card-3639-tag-coverage|3639
feature/card#88-fix|88
refactor: hoist temp-dir cleanup across selftests (card#5085, roundtable #148) (#473)|5085
card#3|3
card42|42
card123|123
card5086|5086
docs/skeleton-crossref-audit-card5086|5086
card007|7
card#007|7
CASES

# `card4` / `card2go` are the near-misses DL-233 MINTED: the glued arm takes two or more
# digits, and the floor is what keeps an ordinary word from naming a card.
for text in "card_123" "card:123" "card #123" "card.123" "card4" "card2go"; do
  eq "near-miss '$text' does not correlate"        ""      "$(tok "$text")"
  eq "near-miss '$text' is FLAGGED"                "true"  "$(near "$text")"
done
# … and the inverse, which is what the mover's `grep -v` gating buys: a glued token that
# CORRELATES must not be warned about, even though the raw probe pattern matches it.
for text in "card123" "card42"; do
  eq "correlating '$text' is NOT flagged as a near-miss" "false" "$(near "$text")"
done
for text in "fix #123 regression" "cards#9" "discard 5 items" "plain refactor"; do
  eq "non-token '$text' stays quiet (no match)"    ""      "$(tok "$text")"
  eq "non-token '$text' stays quiet (no warn)"     "false" "$(near "$text")"
done
# PROVE-TO-FAIL: the old hash-only pattern MISSES exactly the shapes DL-201 rescued. If any
# of these starts matching under OLD_RE, this matrix has stopped proving a real difference.
for text in "card-123" "card#3054_fix" "card-123-foo" "feature/card-3639-tag-coverage"; do
  eq "OLD pattern misses '$text' (the defect DL-201 closed)" "" "$(oldtok "$text")"
  [ -n "$(tok "$text")" ] && ok "NEW pattern rescues '$text'" || bad "NEW pattern must correlate '$text'"
done
# … and against the PRE-DL-233 pattern, which is the one this mover actually carried until
# card#5274. Without this leg the matrix could not tell a widened CARD_RE from a stale one —
# exactly how the python twin stayed green through the whole divergence.
for text in "card123" "card5086" "docs/skeleton-crossref-audit-card5086" "card007"; do
  eq "PRE-DL-233 pattern misses '$text' (the defect card#5274 closed)" "" "$(pre233 "$text")"
  [ -n "$(tok "$text")" ] && ok "NEW pattern rescues '$text'" || bad "NEW pattern must correlate '$text'"
done
# The floor is a REAL constraint, not a side effect: both patterns must still reject these.
for text in "card4" "card2go"; do
  eq "the two-digit floor holds under the OLD pattern too: '$text'" "" "$(pre233 "$text")"
  eq "  … and under the current one"               ""      "$(tok "$text")"
done
eq "plain hash form survived old→new (additive)"   "123"   "$(oldtok 'card#123')"

echo "== 22. STRANDED-CARD RESIDUE REPORT — the negative complement (card#5128) =="
# The mover is positive-only BY DESIGN, so a card whose shipped commit carried no parseable
# `card#<id>` token is INVISIBLE to it: never matched, never moved, never mentioned. Exit 3
# fires when a TOKEN names no card; nothing fired for the inverse. Measured on board 13
# (2026-07-24): 11 of 122 released cards had no token reachable on main. These cases pin the
# report that makes that residue visible — and pin, hard, the two properties that keep it
# safe: it PROMOTES NOTHING, and it NEVER MOVES THE EXIT CODE.
#
# 22a. It CATCHES a Shipped-class card that no token in the range named.
# RED when: the residue report is removed, or its `named` exclusion is inverted → the two
# stranded cards go unmentioned and the run is green and silent (the pre-card#5128 behavior).
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[
  {"id":101,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null,"title":"named by the range"},
  {"id":102,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null,"title":"also named"},
  {"id":4867,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null,"title":"absorbed into card 4869"},
  {"id":5076,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null,"title":"no commit anywhere"}]' 4 1
run
eq "stranded cards do NOT change the exit code"    "0"     "$rc"
eq "the report fires"                              "true"  "$(has 'STRANDED-CARD REPORT' "$err")"
eq "  … against the shipped-class denominator"     "true"  "$(has '2 of 4 Shipped-class card(s)' "$err")"
eq "  … names the absorbed-into-a-sibling card"    "true"  "$(has 'card#4867' "$err")"
eq "  … names the never-shipped card"              "true"  "$(has 'card#5076' "$err")"
eq "  … carries the title so a human can triage"   "true"  "$(has 'absorbed into card 4869' "$err")"
# The exclusion is by NAMED-set, not by current stage: this board fixture still shows 101/102
# in the Shipped-class stage (a real board read races the moves it just made), and they must
# NOT be called stranded. RED when: the exclusion is keyed on the post-move stage instead of $IDS.
eq "a card the range NAMED is never called stranded" "false" "$(has 'card#101' "$err")"
# THE LOAD-BEARING NEGATIVE — the report is observability, NOT a second promotion path. Every
# promotion decision stays positive-only; nothing is ever moved for being ABSENT from a range.
# RED when: the report is ever wired to a PATCH → these three flip and the terminal stage
# becomes reachable by absence.
eq "the report PROMOTES NOTHING (no PATCH to 4867)" "false" "$(has '/tasks/4867' "$patched")"
eq "the report PROMOTES NOTHING (no PATCH to 5076)" "false" "$(has '/tasks/5076' "$patched")"
eq "  … and does not even read them per-card"      "false" "$(has '/tasks/4867.json' "$calls")"

# 22b. It stays QUIET when every Shipped-class card IS named — and prints the census it took,
# so "quiet" is a MEASUREMENT rather than a silence. A report that cannot tell 22a from 22b
# is worthless, which is why the pair is asserted together.
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[
  {"id":101,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null},
  {"id":102,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null},
  {"id":999,"board_id":13,"workflow_stage_id":93,"archived_at":null,"deleted_at":null}]' 3 1
run
eq "every shipped card named → rc 0"               "0"     "$rc"
eq "every shipped card named → NO alarm"           "false" "$(has 'STRANDED-CARD REPORT' "$err")"
eq "  … but the census is still printed"           "true"  "$(has '0 unnamed: all 2 Shipped-class card(s)' "$out")"
eq "  … including the size of the board read"      "true"  "$(has 'board read: 3 card(s)' "$out")"

# 22c. Archived, out-of-board and non-Shipped-class cards are NOT residue.
# RED when: any of the three `select`s is dropped → 5001 (archived), 5002 (board 3) or 5003
# (Backlog) is reported as stranded, i.e. the report cries wolf and gets ignored.
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[
  {"id":101,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null},
  {"id":102,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null},
  {"id":5001,"board_id":13,"workflow_stage_id":97,"archived_at":"2026-07-01T00:00:00+00:00","deleted_at":null},
  {"id":5002,"board_id":3,"workflow_stage_id":97,"archived_at":null,"deleted_at":null},
  {"id":5003,"board_id":13,"workflow_stage_id":91,"archived_at":null,"deleted_at":null}]' 5 1
run
eq "archived / out-of-board / non-shipped are not residue" "false" "$(has 'STRANDED-CARD REPORT' "$err")"
eq "  … and the census counts only the live in-board shipped set" "true" "$(has 'all 2 Shipped-class card(s)' "$out")"

# 22d. A BLIND read (0 visible cards) is UNAVAILABLE, never "0 stranded" — the 200-but-empty
# class this whole tool exists to make loud, applied to its own report.
# RED when: the read_n==0 guard is removed → the run prints a clean bill it never measured.
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[]' 0 1
run
eq "blind residue read does not change the exit code" "0"  "$rc"
eq "blind residue read → UNAVAILABLE"              "true"  "$(has 'stranded-card report UNAVAILABLE' "$err")"
eq "  … blames board visibility"                   "true"  "$(has 'blind read' "$err")"
eq "  … and prints NO false all-clear"             "false" "$(has '0 unnamed' "$out")"

# 22e. A SHORT read (pages deliver fewer rows than the board's own meta.total) is UNAVAILABLE.
# RED when: the census is dropped → a truncated board read reports "0 unnamed" while the
# stranded cards sit in the part that was never fetched.
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[
  {"id":101,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null},
  {"id":102,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null}]' 500 1
run
eq "short residue read → UNAVAILABLE"              "true"  "$(has 'INCOMPLETE read' "$err")"
eq "  … names the shortfall"                       "true"  "$(has 'reports 500 cards but the pages delivered only 2' "$err")"
eq "  … and prints NO false all-clear"             "false" "$(has '0 unnamed' "$out")"

# 22f. PAGING: a stranded card on page 2 is found. Page 1 is a FULL 200-row page, so the
# n<200 break must not fire and last_page=2 must not stop the scan at page 1.
# RED when: the loop breaks at page 1 → card#4867 is never read and the run reports 0 unnamed.
BIGPAGE="$(jq -nc '[range(7000;7200) | {id: ., board_id:13, workflow_stage_id:93, archived_at:null, deleted_at:null}]')"
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 "$BIGPAGE" 201 2
board_page 2 '[{"id":4867,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null}]' 201 2
run
eq "page 2 is actually fetched"                    "true"  "$(has 'page=2' "$calls")"
eq "a stranded card on PAGE 2 is reported"         "true"  "$(has 'card#4867' "$err")"
eq "  … and the paged read is not called short"    "false" "$(has 'INCOMPLETE read' "$err")"

# 22g. An HTTP error on the residue read is UNAVAILABLE and still does not touch the exit code.
reset_fixtures
card 101 13 97; card 102 13 97
printf '500' > "$FIX/board.page1.code"
run
eq "failed residue read does not change the exit code" "0" "$rc"
eq "failed residue read → UNAVAILABLE + status"    "true"  "$(has 'HTTP 500' "$err")"

# 22h. --cards is a TARGETED promote, not a release: its complement is not residue, so the
# report is skipped — LOUDLY, on stdout, never by silence.
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[{"id":4867,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null}]' 1 1
run --cards "101, card#102"
eq "--cards says the report was skipped"           "true"  "$(has 'stranded-card report SKIPPED' "$out")"
eq "  … and does not report a fake residue"        "false" "$(has 'STRANDED-CARD REPORT' "$err")"
eq "  … and reads no board page at all"            "false" "$(has 'page=' "$calls")"

# 22i. EXIT-CODE INDEPENDENCE — the residue never smuggles itself into 1/3/4, and the other
# codes still fire alongside it. RED when: the report is given a code of its own, or is folded
# into `unmatched` → rc changes and one of the two messages disappears.
reset_fixtures
board_page 1 '[{"id":4867,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null}]' 1 1
run --base v0.0.1 --head "$SQUASH_SHA"
eq "squash tip + stranded → rc STAYS 4"            "4"     "$rc"
eq "  … the incomplete-derivation FAILED is reported" "true" "$(has 'NOT a merge commit' "$err")"
eq "  … AND the residue is reported"               "true"  "$(has 'STRANDED-CARD REPORT' "$err")"
reset_fixtures
card 101 13 97
printf '404' > "$FIX/102.code"
board_page 1 '[{"id":4867,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null}]' 1 1
run
eq "unmatched token + stranded → rc STAYS 3"       "3"     "$rc"
eq "  … the unmatched-token FAILED is reported"    "true"  "$(has 'matched no card' "$err")"
eq "  … AND the residue is reported"               "true"  "$(has 'STRANDED-CARD REPORT' "$err")"

# 22j. The alarm is mirrored to the Actions job summary. A WARN-only signal that only reaches
# stderr is invisible in a GREEN job's collapsed step log — the report would be built and then
# never read. RED when: the residue_alarm summary leg is dropped → the file stays empty.
SUMFILE="$TMP/stepsum.md"
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[{"id":4867,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null}]' 1 1
: > "$SUMFILE"
run_with GITHUB_STEP_SUMMARY "$SUMFILE"
eq "the alarm reaches the job summary"             "true"  "$(has 'STRANDED-CARD REPORT' "$(cat "$SUMFILE")")"
eq "  … naming the stranded card there too"        "true"  "$(has 'card#4867' "$(cat "$SUMFILE")")"
# A QUIET run must write NO summary noise, or the surface becomes wallpaper.
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[{"id":999,"board_id":13,"workflow_stage_id":93,"archived_at":null,"deleted_at":null}]' 1 1
: > "$SUMFILE"
run_with GITHUB_STEP_SUMMARY "$SUMFILE"
eq "a quiet run writes nothing to the job summary" ""      "$(cat "$SUMFILE")"
# An UNWRITABLE summary path must never fail a release — observability is not a gate.
# RED when: the `2>/dev/null || true` guard is dropped → set -e kills the run at rc 1/2.
reset_fixtures
card 101 13 97; card 102 13 97
board_page 1 '[{"id":4867,"board_id":13,"workflow_stage_id":97,"archived_at":null,"deleted_at":null}]' 1 1
run_with GITHUB_STEP_SUMMARY "$TMP/no-such-dir/summary.md"
eq "an unwritable summary path is NOT fatal"       "0"     "$rc"
eq "  … and stderr still carries the alarm"        "true"  "$(has 'STRANDED-CARD REPORT' "$err")"

if [ "$fails" -gt 0 ]; then
  echo "promote-cards-by-token.selftest: $fails check(s) FAILED" >&2
  exit 1
fi
echo "promote-cards-by-token.selftest: all checks passed"

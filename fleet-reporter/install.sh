#!/usr/bin/env bash
# fleet-reporter/install.sh — install, upgrade or re-check fleet-reporter on a Linux seat, without root
# (card#7336).
#
# IT RUNS THE RUNBOOK; IT IS NOT A SECOND COPY OF IT. Every command that installs anything lives in a
# fenced block of `INSTALL-LINUX.md`, beside this file. Each block carries an anchor on the line above
# it, `<!-- install.sh: run <name> -->` or `<!-- install.sh: skip — <reason> -->`, and this script reads
# the blocks it names out of that file and runs them unchanged, in this shell, with the runbook's
# `<placeholders>` filled by variable references (never by pasted values). What this file owns is the
# ORDER, the conditions under which a block runs, and the reading of each block's outcome. Before it
# runs anything it checks the runbook against itself (`--check-runbook`, below) and refuses a runbook
# it does not match. `INSTALL-LINUX.md` § Running it with `install.sh` says what runs when, what each
# exit code means and what a second run does; `AGENT-INSTALL.md` is the guide an agent follows.
#
# Usage:
#   install.sh [--site <url>] [--seat-id <id>] [--install-id <id>] [--agent-name <name>] [--ca-file <path>]
#              [--no-board]
#   install.sh --set-token [--site <url> --seat-id <id> [--install-id <id>] [--agent-name <name>]
#              [--ca-file <path>]] [--site-name <name>] [--no-board]
#
# --no-board leaves the seat's board declaration unset (card#12447): Step 2's `board-identity` block then
# declares nothing, and clears a declaration the config already holds. There is no flag to TYPE a board user:
# a user an operator chooses is the server's pin (`mezzanine:seat-board-user`), never a seat's declaration.
#   install.sh --check-runbook [<runbook>]
#
# Exit: 0 installed and every check passed, or nothing to change · 1 refused, or a step or check failed
#       · 2 installed, and a check could not be measured · 3 the seat token comes first: the output names
#       the command the user runs for it.
set -euo pipefail

MZI_HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)
export MZI_CHECKOUT=${MZI_HERE%/fleet-reporter}
MZI_RUNBOOK=$MZI_HERE/INSTALL-LINUX.md

# Every block this script runs, by its anchor name. `--check-runbook` holds this list and the runbook's
# `run` anchors to each other, both ways.
MZI_BLOCKS=(prereqs backup u1 artifact values resolver identity board-identity writer token-prompt token-config
  token-rotate token-shred config-check hooks-scripts hooks-apply todo-tools cron-helpers cron-write cron-ancestry
  holder restart selftest health arrival)
# Text this script reads in a block's output, as `<block>|<text>`. `--check-runbook` holds each to the
# block's own source, so a reworded message reds the check instead of being read as the other outcome.
MZI_MARKERS=(
  "u1|NO-OP: this seat already runs"
  "hooks-apply|already wired: nothing applied"
  "cron-write|crontab already current"
  "cron-helpers|it runs the previous build"
  "identity|is not a member: disagreed"
  "board-identity|board identity: unchanged"
  "todo-tools|: unchanged"
  "token-config|pipeline rc="
  "token-rotate|pipeline rc="
  "selftest|selftest rc="
  "health|health rc="
  "arrival|arrival rc="
)
# The runbook's placeholders a `run` block may hold, each filled with a REFERENCE to the variable named,
# so a value is never pasted into shell text.
MZI_FILL=(
  "<checkout>|MZI_CHECKOUT"
  "<install_id>|MZI_INSTALL_ID"
  "<seat_id>|MZI_SEAT_ID"
  "<protocol_agent_name>|MZI_AGENT"
  "<ingest_url>|MZI_URL"
  "<issued_by>|MZI_BY"
  "<server_checkout>|MZI_SERVER"
)

MZI_CFG=$HOME/.config/fleet-reporter/config.json
MZI_JS=$HOME/.local/share/fleet-reporter/fleet-reporter.js
MZI_TOKEN_FILE=$HOME/.config/fleet-reporter/mezzanine-reporter-token
MZI_ATTEMPTS=$HOME/.local/state/fleet-reporter-install

say() { printf 'install.sh: %s\n' "$*"; }
die() { printf 'install.sh: %s\n' "$*" >&2; exit 1; }

# ── the runbook ──────────────────────────────────────────────────────────────────────────────────

# The fenced bash block under `<!-- install.sh: run <name> -->`, verbatim. Fails unless that anchor is
# followed directly by a ```bash fence that closes.
block_source() {
  awk -v a="<!-- install.sh: run $1 -->" '
    st == 0 && $0 == a { st = 1; next }
    st == 1 { if ($0 == "```bash") { st = 2; next } exit }
    st == 2 { if ($0 == "```") { st = 3; exit } print }
    END { exit st == 3 ? 0 : 1 }' "${2:-$MZI_RUNBOOK}"
}

# Every finding about the runbook against this script, one per line; no output is a match.
runbook_findings() {
  local rb=$1 names name n entry blk text src
  [ -r "$rb" ] || { echo "cannot read the runbook $rb"; return; }
  awk '
    pending { if ($0 != "```bash") printf "line %d: the install.sh anchor on line %d is not followed by a ```bash block\n", NR, NR - 1; pending = 0 }
    /^<!-- install\.sh:/ {
      if ($0 !~ /^<!-- install\.sh: (run [a-z0-9-]+|skip — .+) -->$/) printf "line %d: an install.sh anchor that is neither `run <name>` nor `skip — <reason>`\n", NR
      pending = 1
    }
    $0 == "```bash" && prev !~ /^<!-- install\.sh: / { printf "line %d: a ```bash block with no install.sh anchor on the line above: say whether install.sh runs it\n", NR }
    { prev = $0 }' "$rb"
  names=$(sed -n 's/^<!-- install\.sh: run \([a-z0-9-]*\) -->$/\1/p' "$rb")
  for name in "${MZI_BLOCKS[@]}"; do
    n=$(printf '%s\n' "$names" | grep -cxF -- "$name" || true)
    [ "$n" = 1 ] || echo "block $name: the runbook holds $n \`run $name\` anchors, not one"
  done
  while IFS= read -r name; do
    [ -n "$name" ] || continue
    case " ${MZI_BLOCKS[*]} " in *" $name "*) ;; *) echo "block $name: the runbook says install.sh runs it, and install.sh does not" ;; esac
  done <<< "$names"
  for entry in "${MZI_MARKERS[@]}"; do
    blk=${entry%%|*} text=${entry#*|}
    src=$(block_source "$blk" "$rb") || continue
    case "$src" in *"$text"*) ;; *) echo "block $blk: install.sh reads [$text] in its output, and the block's source does not hold it" ;; esac
  done
}

check_runbook() {
  local rb=$1 f
  f=$(runbook_findings "$rb")
  if [ -n "$f" ]; then printf '%s\n' "$f" | sed 's/^/  /' >&2; echo "the runbook $rb does not match install.sh: NOT MATCHED" >&2; return 1; fi
  echo "the runbook $rb matches install.sh: every bash block is classified, every block install.sh runs is there once, and every message it reads is in its block"
}

# The block with its placeholders filled by references. A placeholder this list does not fill stays in the
# text, where bash reads a `<x>` outside quotes as a redirect from a file named x, and the block fails on it.
block_text() {
  local body entry ph var ref
  body=$(block_source "$1") || { echo "install.sh: INSTALL-LINUX.md holds no block \`run $1\`: NOT RUN" >&2; return 1; }
  for entry in "${MZI_FILL[@]}"; do
    ph=${entry%%|*} var=${entry#*|}
    ref="\"\$$var\""
    body=${body//"$ph"/$ref}
  done
  printf '%s\n' "$body"
}

# Run a block in this shell (Step 0 and Step 2's values export into it), keeping its output in
# $MZI_OUT/<name>.out for the reads below and showing it once the block ends. Returns the block's own
# status. The output goes to a FILE and never through a pipe: Step 1's hook starts a detached flusher,
# which keeps every descriptor it inherits open for its whole life, so a reader on a pipe (`tee`) would
# wait for an end of file that never comes.
run() {
  local name=$1 body rc=0
  body=$(block_text "$name") || return 1
  say "── INSTALL-LINUX.md block \`$name\`${2:+ ($2)}"
  eval "$body" > "$MZI_OUT/$name.out" 2>&1 || rc=$?
  cat "$MZI_OUT/$name.out"
  return "$rc"
}
said() { grep -qF -- "$2" "$MZI_OUT/$1.out"; }
rc_of() { sed -n "s/^$2 rc=\([0-9]*\)\$/\1/p" "$MZI_OUT/$1.out" | tail -n 1; }

# ── the arguments ────────────────────────────────────────────────────────────────────────────────

MZI_MODE=install MZI_SITE="" MZI_SEAT_ID="" MZI_INSTALL_ID="" MZI_AGENT="" MZI_CA="" MZI_SITE_NAME=""
MZI_CHECK_RB=$MZI_RUNBOOK
while [ $# -gt 0 ]; do
  case "$1" in
    --set-token) MZI_MODE=set-token ;;
    --check-runbook) MZI_MODE=check; if [ $# -gt 1 ] && [ "${2#--}" = "$2" ]; then MZI_CHECK_RB=$2; shift; fi ;;
    --no-board) export FR_NO_BOARD=1 ;;
    --site|--seat-id|--install-id|--agent-name|--ca-file|--site-name)
      [ $# -gt 1 ] || die "$1 needs a value: NOTHING RUN"
      case "$1" in
        --site) MZI_SITE=$2 ;; --seat-id) MZI_SEAT_ID=$2 ;; --install-id) MZI_INSTALL_ID=$2 ;;
        --agent-name) MZI_AGENT=$2 ;; --ca-file) MZI_CA=$2 ;; --site-name) MZI_SITE_NAME=$2 ;;
      esac
      shift ;;
    -h|--help) sed -n '2,/^set -euo/p' "${BASH_SOURCE[0]}" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
    *) die "unknown argument [$1]; --help lists them: NOTHING RUN" ;;
  esac
  shift
done

if [ "$MZI_MODE" = check ]; then check_runbook "$MZI_CHECK_RB"; exit; fi
check_runbook "$MZI_RUNBOOK" > /dev/null || die "INSTALL-LINUX.md and this script disagree (the lines above say where): NOTHING RUN"

# D1 § 3.1's patterns for the identity, § 3.1's https:// rule for the URL. A value that would not fit a
# crontab path or a shell word is refused here rather than by a block halfway through.
[ -z "$MZI_SEAT_ID" ] || [[ $MZI_SEAT_ID =~ ^[a-z0-9][a-z0-9-]{1,47}$ ]] || die "--seat-id [$MZI_SEAT_ID] is not a seat_id (D1 § 3.1: ^[a-z0-9][a-z0-9-]{1,47}\$): NOTHING RUN"
[ -z "$MZI_INSTALL_ID" ] || [[ $MZI_INSTALL_ID =~ ^[a-z0-9][a-z0-9-]{1,31}$ ]] || die "--install-id [$MZI_INSTALL_ID] is not an install_id (D1 § 3.1: ^[a-z0-9][a-z0-9-]{1,31}\$): NOTHING RUN"
[ -z "$MZI_SITE_NAME" ] || [[ $MZI_SITE_NAME =~ ^[a-z0-9][a-z0-9-]{1,31}$ ]] || die "--site-name [$MZI_SITE_NAME] is not a site name (D1 § 3.1): NOTHING RUN"
[ -z "$MZI_AGENT" ] || [[ $MZI_AGENT =~ ^[A-Za-z0-9][A-Za-z0-9._-]*$ ]] || die "--agent-name [$MZI_AGENT] is not a roster name: NOTHING RUN"
MZI_URL=""
if [ -n "$MZI_SITE" ]; then
  [[ $MZI_SITE =~ ^https://[^[:space:]%\"\'\\]+$ ]] || die "--site [$MZI_SITE] must be an https:// URL with no space, quote or % (D1 § 3.5): NOTHING RUN"
  MZI_URL=${MZI_SITE%/}
  case "$MZI_URL" in */api/ingest/events) ;; *) MZI_URL=$MZI_URL/api/ingest/events ;; esac
fi
if [ -n "$MZI_CA" ]; then
  case "$MZI_CA" in /*) ;; *) die "--ca-file must be an absolute path (D1 § 3.1): NOTHING RUN" ;; esac
  [ -r "$MZI_CA" ] || die "--ca-file $MZI_CA is not readable: NOTHING RUN"
fi
# The install_id the Seat tokens page is told, when none is given: this account's name, as a slug.
if [ -z "$MZI_INSTALL_ID" ] && [ ! -f "$MZI_CFG" ]; then
  MZI_INSTALL_ID=$(id -un | tr 'A-Z_.' 'a-z--' | tr -cd 'a-z0-9-' | cut -c1-32)
  [[ $MZI_INSTALL_ID =~ ^[a-z0-9][a-z0-9-]{1,31}$ ]] || die "this account's name gives no install_id; pass --install-id: NOTHING RUN"
fi
export MZI_INSTALL_ID MZI_SEAT_ID MZI_AGENT MZI_URL
export MZI_BY="install.sh" MZI_SERVER=""

MZI_OUT=$(mktemp -d)
trap 'rm -rf -- "$MZI_OUT"' EXIT

# The command a user runs for the token, with this run's own values.
token_command() {
  local c
  printf -v c 'bash %q --set-token' "$MZI_HERE/install.sh"
  if [ -n "$MZI_SITE" ]; then c="$c --site $(printf %q "$MZI_SITE")"; else c="$c --site <site URL>"; fi
  c="$c --install-id $MZI_INSTALL_ID --seat-id ${MZI_SEAT_ID:-<seat id>}"
  [ -z "$MZI_AGENT" ] || c="$c --agent-name $MZI_AGENT"
  [ -z "$MZI_CA" ] || c="$c --ca-file $(printf %q "$MZI_CA")"
  printf '%s\n' "$c"
}

# install_id, seat_id and each site's "name ingest_url" in the config, read without printing its token.
config_identity() {
  FR_CONFIG=$MZI_CFG node -e '
const c = JSON.parse(require("fs").readFileSync(process.env.FR_CONFIG, "utf8"));
console.log(String(c.install_id)); console.log(String(c.seat_id));
const sites = Array.isArray(c.sites) ? c.sites : [{ name: "default", ingest_url: c.ingest_url }];
for (const s of sites) console.log(String(s && s.name) + " " + String(s && s.ingest_url));'
}

# Remove this run's attempt directory: only ever the one Step 0 made for it, under the attempts directory.
drop_attempt() {
  case "${B:-}" in "$MZI_ATTEMPTS"/[0-9]*Z) rm -rf -- "$B"; say "nothing changed, so this run's attempt directory is removed" ;; esac
}

# ── --set-token: the token, at a hidden prompt, into the config ──────────────────────────────────

set_token() {
  local rotate=0 carried=0 wrote=0
  if [ -f "$MZI_CFG" ]; then
    rotate=1
    [ -f "$MZI_JS" ] || die "a config is already at $MZI_CFG and the reporter is not installed: run install.sh first, then --set-token to replace the token: NOTHING RUN"
  else
    if [ -z "$MZI_URL" ] || [ -z "$MZI_SEAT_ID" ]; then die "no config yet, so --set-token needs --site and --seat-id (and --install-id, or this account's name is used): NOTHING RUN"; fi
  fi
  if [ -e "$MZI_TOKEN_FILE" ] || [ -L "$MZI_TOKEN_FILE" ]; then
    carried=1
    say "a token file is already at $MZI_TOKEN_FILE: it is used as the token, and no prompt is shown"
  elif ! { : < /dev/tty; } 2> /dev/null; then
    die "there is no terminal to read the token from, so nothing was asked and NOTHING WRITTEN. Run this command in a terminal on this machine, as $(id -un), where the token can be typed with nothing echoed: $(token_command)"
  fi
  run prereqs || die "a prerequisite is missing (the line above names it): NOTHING WRITTEN"
  run backup || die "Step 0 failed: NOTHING WRITTEN"
  run writer || die "the config writer could not be saved: NOTHING WRITTEN"
  if [ "$rotate" = 0 ]; then
    run values || die "Step 2's values block failed: NOTHING WRITTEN"
    run resolver || die "the COORD_CONFIG resolver could not be saved: NOTHING WRITTEN"
    if [ -n "$MZI_AGENT" ]; then
      run identity || die "the roster that --agent-name is checked against could not be read: NOTHING WRITTEN"
      ! said identity "is not a member: disagreed" || die "--agent-name $MZI_AGENT is not in the roster (above), so its desk would join no post: NOTHING WRITTEN"
    fi
    # card#12447: the seat's board user, from its own board credential, for the writer below to carry.
    run board-identity || die "Step 2's board-identity block failed (above): NOTHING WRITTEN"
  fi
  if [ "$carried" = 0 ]; then
    run token-prompt || die "the token was not written to $MZI_TOKEN_FILE: NOTHING WRITTEN"
  fi
  if [ "$rotate" = 0 ]; then
    if [ -n "$MZI_CA" ]; then export FR_CA_FILE=$MZI_CA; fi
    run token-config || true
    unset FR_CA_FILE
    [ "$(rc_of token-config pipeline)" = 0 ] && wrote=1
  else
    export SEAT_SITE=${MZI_SITE_NAME:-default}
    run token-rotate || true
    [ "$(rc_of token-rotate pipeline)" = 0 ] && wrote=1
  fi
  if [ "$wrote" = 1 ] || [ "$carried" = 0 ]; then
    run token-shred || die "the token file could not be removed: remove $MZI_TOKEN_FILE yourself"
  fi
  [ "$wrote" = 1 ] || die "the config was not written (the writer's line above says why). $([ "$carried" = 1 ] && echo "The token file at $MZI_TOKEN_FILE, which was there before this run, is left in place: if it is not the token, delete it and run this command again to be asked for the token." || echo "The token file is removed: run this command again and paste the token again.")"
  run config-check || die "the config check failed"
  if [ "$rotate" = 1 ]; then
    run cron-helpers || die "Step 5's helpers could not be saved"
    run restart "stops the flusher, waits 70 s for its next start, and checks it" || die "the token is replaced, and the flusher was not restarted on it (above): run install.sh"
    say "the token of site ${SEAT_SITE} is replaced. Revoke the old token by its prefix on the site's Seat tokens page once install.sh reports the site current"
  else
    say "the token is in the config. Next: run install.sh with the same values, which installs the rest:"
    say "  bash $(printf %q "$MZI_HERE/install.sh") --site $(printf %q "$MZI_SITE") --install-id $MZI_INSTALL_ID --seat-id $MZI_SEAT_ID${MZI_AGENT:+ --agent-name $MZI_AGENT}${MZI_CA:+ --ca-file $(printf %q "$MZI_CA")}"
  fi
}

# ── install / upgrade / re-check ─────────────────────────────────────────────────────────────────

install() {
  local artifact=0 changed=0 board=0 cc st h a v verdict=0 id
  run prereqs || die "a prerequisite is missing (the line above names it): NOTHING INSTALLED"
  if [ ! -f "$MZI_CFG" ]; then
    if [ -z "$MZI_URL" ] || [ -z "$MZI_SEAT_ID" ]; then die "no config at $MZI_CFG yet: pass --site and --seat-id, and install.sh names the token step: NOTHING RUN"; fi
    say "THE SEAT TOKEN COMES FIRST, and nothing is installed yet."
    say "  1. On ${MZI_URL%/api/ingest/events}/admin/tokens, issue a token for install id \`$MZI_INSTALL_ID\` and seat id \`$MZI_SEAT_ID\`, and keep the prefix the page shows."
    say "  2. In a terminal on this machine, as $(id -un), run the command below and paste the token at its prompt; nothing is echoed."
    say "     In Claude Code, type it after \`!\`; if it reports that there is no terminal, run it in a terminal of your own."
    say "       $(token_command)"
    say "  3. Then run this command again."
    exit 3
  fi
  id=$(config_identity) || die "the config at $MZI_CFG cannot be read as JSON: NOTHING RUN"
  if [ -n "$MZI_INSTALL_ID" ] && [ "$MZI_INSTALL_ID" != "$(sed -n 1p <<< "$id")" ]; then die "the config is for install_id $(sed -n 1p <<< "$id"), not $MZI_INSTALL_ID: NOTHING RUN"; fi
  if [ -n "$MZI_SEAT_ID" ] && [ "$MZI_SEAT_ID" != "$(sed -n 2p <<< "$id")" ]; then die "the config is for seat_id $(sed -n 2p <<< "$id"), not $MZI_SEAT_ID: NOTHING RUN"; fi
  if [ -n "$MZI_URL" ] && ! sed -n '3,$p' <<< "$id" | cut -d' ' -f2- | grep -qxF -- "$MZI_URL"; then die "the config's sites ($(sed -n '3,$p' <<< "$id" | tr '\n' ' ')) do not include $MZI_URL: NOTHING RUN"; fi

  run backup || die "Step 0 failed: NOTHING INSTALLED"
  if [ -f "$MZI_JS" ]; then
    run u1 || die "the installed artifact could not be compared with this checkout's"
    said u1 "NO-OP: this seat already runs" || artifact=1
  else
    artifact=1
  fi
  if [ "$artifact" = 1 ]; then run artifact || die "Step 1 failed (above)"; changed=1; fi
  run resolver || die "the COORD_CONFIG resolver could not be saved"
  # card#12447: re-resolved on EVERY run, upgrades included, so a moved layout or a changed answer reaches the
  # config. A declaration it rewrote reaches the heartbeat only from the flusher's next start, so it owes U6.
  run board-identity || die "Step 2's board-identity block failed (above): the config is unchanged"
  said board-identity "board identity: unchanged" || { changed=1; board=1; }
  run hooks-scripts || die "Step 4's scripts could not be saved"
  run hooks-apply || die "Step 4 (a) refused or failed (above): settings.json is unchanged unless the block printed \`applied\`"
  said hooks-apply "already wired: nothing applied" || changed=1
  # card#12447: the harness's to-do tools, set in the same file through the same stage-check-apply pipeline.
  run todo-tools || die "Step 4 (c) refused or failed (above): settings.json is unchanged unless the block printed \`set\`"
  said todo-tools ": unchanged" || changed=1
  run cron-helpers || die "Step 5's helpers could not be saved"
  run cron-write || die "Step 5 refused or failed (above)"
  said cron-write "crontab already current" || changed=1
  # U6 is owed when the RUNNING flusher is older than the installed artifact, whichever run installed it:
  # U1 compares files only, so a run that installed the artifact and failed before U6 leaves it NO-OP.
  run holder || true
  if said holder "it runs the previous build" || [ "$board" = 1 ]; then
    run restart "stops the flusher, waits 70 s for its next start, and checks it" || die "the flusher does not yet run the new build (above): run install.sh again"
    changed=1
  fi
  run config-check || die "the config check failed"

  # Step 6 and U7 read the roster through $COORD_CONFIG as the flusher has it: Step 5's resolved value.
  cc=$(node "$B/coord-config.js" "$HOME/.claude/settings.json" "$HOME/.claude/settings.local.json")
  if [ -n "$cc" ]; then export COORD_CONFIG=$cc; else unset COORD_CONFIG; fi
  run selftest "probes each site" || true
  st=$(rc_of selftest selftest)
  run health || true
  h=$(rc_of health health)
  run arrival "waits up to 150 s for each site to accept a batch" || true
  a=$(rc_of arrival arrival)
  if [ "$changed" = 1 ]; then run cron-ancestry || say "(this check is informational: a flusher a hook started holds the same lock, and Step 5 says when to check cron's start again)"; fi

  for v in "$st" "$h" "$a"; do
    case "$v" in 0) ;; 2) [ "$verdict" = 1 ] || verdict=2 ;; *) verdict=1 ;; esac
  done
  say "selftest rc=${st:-?} · health rc=${h:-?} · arrival rc=${a:-?}"
  if [ "$changed" = 0 ]; then drop_attempt; fi
  case "$verdict" in
    0) if [ "$changed" = 1 ]; then say "INSTALLED: the server holds this build current and every site has accepted a batch; the desk is on the site's floor"; else say "ALREADY CURRENT: nothing changed, and every check passed"; fi ;;
    2) say "$([ "$changed" = 1 ] && echo INSTALLED || echo UNCHANGED), and NOT VERIFIED: a check above could not be measured; its block's lines say why" ;;
    *) say "A CHECK FAILED (above): the block's lines name it, and INSTALL-LINUX.md Step 6 and Step 7 read each code" ;;
  esac
  exit "$verdict"
}

if [ "$MZI_MODE" = set-token ]; then set_token; else install; fi

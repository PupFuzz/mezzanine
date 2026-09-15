#!/usr/bin/env bash
# env-mirror-diff.sh — bin/deploy.sh mirrors Laravel's `.env` parser in bash. This is the standing check
# that the mirror still agrees with the parser it mirrors.
#
# WHY IT EXISTS. `bin/deploy.sh` cannot ask PHP what `server/.env` means: at phase A the config cache is
# stale by construction, and the host may not have a working app at all. So it re-implements, in bash, the
# part of vlucas/phpdotenv that decides where a line ENDS, whether a line is a SETTING, and what the app
# then RECEIVES for it. Every one of those is a claim about somebody else's code. card#9561 round 4 is what
# a false one costs: while the scan split on `\r\n`, `\n` and `\r` alike and the reader shelled out to
# `grep`, whose terminator is `\n` only, a `.env` ending `# note\rDB_HOST=db.internal` was TWO lines to
# Dotenv and ONE to the reader that decides — Laravel went to `db.internal` over TCP in plaintext while A5
# read DB_HOST as unset, took the config default of 127.0.0.1, and exempted the store from TLS. It was
# found by a differential built in a scratchpad and thrown away, four rounds running. This is that
# differential, landed: a phpdotenv upgrade that moves the parser out from under the mirror reds HERE, on a
# PR, instead of in a maintenance window.
#
# WHAT IT COMPARES. Two differentials, each over a population this script DERIVES on every run:
#
#   scan     — what phpdotenv's own parser makes of the FILE (does it parse at all, and what does it HOLD
#              for each key `bin/deploy.sh` reads) beside what `env_file_scan` + `env_lines_load` + `env_get`
#              make of it. The dangerous cells: the scan ACCEPTS a file phpdotenv REJECTS — phase A then
#              certifies a file phase B cannot boot on, inside the window — or both accept and the script
#              READS A DIFFERENT VALUE than Dotenv holds.
#   locality — where the app actually CONNECTS, resolved through the real `server/config/database.php`,
#              `ConfigurationUrlParser` and `MySqlConnector::getDsn()`, beside what A5 DECIDES. The
#              dangerous cell: the app is on another host with no CA, or on a cache that keeps nothing,
#              and A5 says `ok`.
#
# THE POPULATION IS DERIVED, NEVER WRITTEN DOWN. The line-ending axis is read out of
# `Dotenv\Parser\Parser::parse`'s own split regex, so a phpdotenv release that adds a terminator adds
# cells here. The keys compared are read out of `bin/deploy.sh`'s own `env_read` call sites, so a script
# that starts reading a new key covers it without an edit. The cell count is COUNTED as the run emits
# cells and printed at the end. Nothing here states a population size, because a written count becomes a
# quoted authority that outlives the run that falsified it.
#
# EVERY DIFFERENTIAL CARRIES A CONTROL SEEN TO FAIL. A differential reporting zero dangerous cells proves
# nothing until it has been shown it can report one. After the real run this script mutates a COPY of
# `bin/deploy.sh` — one defect per mutant, each a defect this repo has actually shipped or nearly shipped —
# and requires the differential to go red. A mutant that does NOT red fails this script, because a
# differential that cannot fail is a decoration. The oracle carries one too: its own batching is mutated
# to leak state between fixtures, and the order-independence check must catch it.
#
# USAGE
#   bin/env-mirror-diff.sh [--deploy PATH] [--only scan|locality] [--verbose]
# Needs `php` with `pdo_mysql`, and `server/vendor/` installed (`composer install` in `server/`) — that
# vendor tree IS the oracle. Reaches no host, needs no credential, writes only inside its own temp dir,
# and never reads or writes the real `server/.env`.
#
# EXIT 0 no dangerous cells and every control red when mutated · 1 a dangerous cell, a refusal of a file
# the app boots on where none is declared, or a control that failed to discriminate · 2 the harness could
# not run (no vendor tree, no pdo_mysql, deploy.sh's reader block moved).

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/.." && pwd)"
export MEZZ_REPO="$REPO"

DEPLOY="$REPO/bin/deploy.sh"
ORACLE="$HERE/env-mirror-diff.oracle.php"
MIRROR="$HERE/env-mirror-diff.mirror.sh"
ONLY=both
VERBOSE=0

while [ $# -gt 0 ]; do
  case "$1" in
    --deploy)  DEPLOY="$2"; shift 2 ;;
    --only)    ONLY="$2"; shift 2 ;;
    --verbose) VERBOSE=1; shift ;;
    -h | --help) sed -n '2,/^$/p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) printf 'env-mirror-diff.sh: unknown argument %s\n' "$1" >&2; exit 2 ;;
  esac
done

die()  { printf '\n⛔ env-mirror-diff.sh: %s\n' "$*" >&2; exit 2; }
say()  { printf '%s\n' "$*"; }
head2() { printf '\n── %s\n' "$*"; }

[ -f "$DEPLOY" ] || die "no such deploy script: $DEPLOY"
command -v php >/dev/null || die 'no php on PATH. The vendored phpdotenv is the oracle; there is nothing to compare against without it.'

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

FAILURES=0
fail() { printf '   ⛔ %s\n' "$*"; FAILURES=$((FAILURES + 1)); }

# ── the line-ending axis, read out of the parser it mirrors ───────────────────────────────────────────
# `Dotenv\Parser\Parser::parse` splits on `Regex::split("/(\r\n|\n|\r)/")`. That regex is the definition of
# "a line" this whole differential is about, so it is READ rather than retyped: an upgrade that adds a
# terminator adds axes here, and one that changes the pattern's shape stops this script rather than
# quietly narrowing it.
PARSER="$REPO/server/vendor/vlucas/phpdotenv/src/Parser/Parser.php"
[ -f "$PARSER" ] || die "no $PARSER — run \`composer install\` in server/. The vendored parser IS the oracle."
SPLIT_PATTERN="$(sed -n 's|.*Regex::split("/(\(.*\))/".*|\1|p' "$PARSER")"
[ -n "$SPLIT_PATTERN" ] || die "could not read Parser::parse's split pattern out of $PARSER"

TERM_NAMES=(); TERM_BYTES=(); LF_INDEX=-1
{
  IFS='|' read -r -a _alts <<< "$SPLIT_PATTERN"
  for _alt in "${_alts[@]}"; do
    _rest="${_alt//\\r/}"; _rest="${_rest//\\n/}"
    [ -z "$_rest" ] || die "Parser::parse now splits on '$_alt', which is not a CR/LF terminator — this harness's line-ending axis no longer describes it"
    _bytes="${_alt//\\r/$'\r'}"; _bytes="${_bytes//\\n/$'\n'}"
    _name="${_alt//\\/}"
    case "$_name" in rn) _name=crlf ;; n) _name=lf ;; r) _name=cr ;; esac
    TERM_NAMES+=("$_name"); TERM_BYTES+=("$_bytes")
    [ "$_bytes" != $'\n' ] || LF_INDEX=$((${#TERM_NAMES[@]} - 1))
  done
}
[ "$LF_INDEX" -ge 0 ] || die "Parser::parse's split pattern does not include a bare \\n; this harness writes its baseline fixtures with one"

# ── the keys compared, read out of the script under test ──────────────────────────────────────────────
# Every `env_read VAR KEY` call site in deploy.sh. A script that starts reading a new key gets that key
# compared with no edit here; a key nothing reads costs no cells.
mapfile -t KEYS < <(grep -oE 'env_read [a-z_]+ [A-Z_][A-Z0-9_]*' "$DEPLOY" | awk '{print $3}' | sort -u)
[ "${#KEYS[@]}" -gt 0 ] || die "no \`env_read VAR KEY\` call sites found in $DEPLOY — the reader has been renamed and this harness would compare nothing"

# ── the loopback set: two statements, checked against each other ──────────────────────────────────────
# Which host names are THIS host is the one judgement the oracle makes that no app code makes, so it is
# stated twice on purpose — once in env-mirror-diff.oracle.php, once in deploy.sh's ENV_LOOPBACK_HOSTS —
# and held together here. An oracle that took the list from the script under test could never disagree
# with it; two copies with no check would drift. Widening either one reds, which is the point: it is an
# operator decision, not a side effect.
check_loopback_sets() {
  local raw_oracle raw_deploy from_oracle from_deploy
  local -a o d
  raw_oracle="$(printf '' | php "$ORACLE" loopback-hosts)" || die 'the oracle would not run — see its message above'
  raw_deploy="$(printf '' | bash "$MIRROR" "$DEPLOY" loopback-hosts)" || die 'the mirror would not run — see its message above'
  # `read -a`, never an unquoted expansion: `[::1]` is a valid glob, and a bare `$list` would have the
  # shell try to match it against the working directory before this ever compared anything.
  read -r -a o <<< "$raw_oracle"
  read -r -a d <<< "$raw_deploy"
  from_oracle="$(printf '%s\n' "${o[@]}" | sort | tr '\n' ' ')"
  from_deploy="$(printf '%s\n' "${d[@]}" | sort | tr '\n' ' ')"
  if [ "$from_oracle" = "$from_deploy" ]; then
    say "   loopback set agrees: $from_deploy"
  else
    fail "the loopback sets have drifted — deploy.sh: [$from_deploy]  oracle: [$from_oracle]"
  fi
}

# ── fixture bases ─────────────────────────────────────────────────────────────────────────────────────
# A base is a NAME, a STRICT flag and the lines it adds under the shared head. STRICT marks a `.env` a
# correct production host may really hold: refusing one, or failing to read a key out of one, is a defect
# of its own and reds this script. Non-strict bases are shapes deploy.sh DECLARES it is narrower than
# phpdotenv about, or shapes phpdotenv itself rejects; those are reported, not failed.
HEAD_LINES=(
  APP_ENV=production
  'APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
  APP_DEBUG=false
  DB_CONNECTION=mysql
  CACHE_STORE=database
  'APP_URL=https://example.invalid'
  # ⚠ LOAD-BEARING, and the last head line on purpose. card#9561 r4's blocker needed a line the parser
  # SKIPS directly above the payload: `# note\rDB_HOST=db.internal` is two lines to Dotenv and one COMMENT
  # to a `\n`-only splitter, so the payload vanishes silently instead of making the line unparseable. Every
  # base's first own line sits under this one, so the single-terminator axis writes that shape for all of
  # them. Without it the `lf-only` control cannot red the locality differential — measured, not assumed.
  '# the settings for this host'
)

BASES=()
addbase() { # addbase NAME STRICT LINE…
  local name="$1" strict="$2"; shift 2
  local IFS=$'\x1f'
  BASES+=("$name$IFS$strict$IFS$*")
}

scan_bases() {
  BASES=()
  # Shapes a correct production .env really takes. Every one of these must be accepted and read identically.
  addbase plain                 1 DB_HOST=db.internal MYSQL_ATTR_SSL_CA=/etc/ssl/ca.crt
  addbase indented              1 '  DB_HOST=db.internal' '	MYSQL_ATTR_SSL_CA=/etc/ssl/ca.crt'
  addbase single_quoted         1 "DB_HOST='db.internal'" "DB_PASSWORD='s3cret'"
  addbase double_quoted         1 'DB_HOST="db.internal"' 'MYSQL_ATTR_SSL_CA="/etc/ssl/ca.crt"'
  addbase empty_value           1 'DB_HOST=' 'DB_SOCKET='
  addbase closed_fold           1 'NOTE="one line, closed"' DB_HOST=db.internal
  addbase blanks_and_comments   1 '' '# a comment' '' DB_HOST=db.internal '   ' '# trailing note'
  addbase comment_with_quote    1 '# FOO="an unterminated quote in a comment' DB_HOST=db.internal
  addbase hash_in_quoted_value  1 "DB_PASSWORD='s3#cret'" DB_HOST=db.internal
  # Shapes phpdotenv REJECTS: the whole file is then unreadable to Laravel, so the scan must refuse too.
  addbase unterminated_single   0 "NOTE='oops" DB_HOST=db.internal
  addbase unquoted_space        0 'MAIL_FROM_NAME=Mezzanine App' DB_HOST=db.internal
  addbase bad_escape            0 'NOTE="a\qb"' DB_HOST=db.internal
  addbase no_name               0 '=oops' DB_HOST=db.internal
  addbase bad_name              0 'NOT-A-NAME=x' DB_HOST=db.internal
  addbase fold_unclosed         0 'NOTE="line one' DB_HOST=db.internal
  # Shapes phpdotenv reads and deploy.sh is DECLARED narrower about, or reads by a rule of its own.
  addbase fold_swallows         0 'NOTE="line one' DB_HOST=db.internal 'line three"'
  addbase fold_in_unquoted      0 'NOTE=a="b' DB_HOST=db.internal
  addbase unicode_name          0 'NOTÉ=x' DB_HOST=db.internal
  addbase export_prefix         0 'export DB_HOST=db.internal'
  addbase quoted_name           0 '"DB_HOST"=db.internal'
  addbase spaced_equals         0 'DB_HOST = db.internal'
  addbase inline_comment        0 'DB_HOST=db.internal # was localhost'
  addbase trailing_space        0 'DB_HOST=db.internal   '
  addbase interpolated          0 DB_DATABASE=mezz 'DB_HOST=db.${DB_DATABASE}.internal'
  addbase doubly_quoted         0 'DB_HOST="'"'"'db.internal'"'"'"'
  addbase duplicate_key         0 DB_HOST=localhost DB_HOST=db.internal
  addbase bare_key              0 DB_HOST
  addbase nul_in_line           0 'NOTE=a@NUL@b' DB_HOST=db.internal
  addbase nul_before_payload    0 '@NUL@' DB_HOST=db.internal MYSQL_ATTR_SSL_CA=/etc/ssl/ca.crt
}

locality_bases() {
  BASES=()
  # The app is on another host, or on a cache that keeps nothing: A5 must refuse every one of these.
  addbase remote_host           0 DB_HOST=db.internal
  addbase remote_url            0 'DB_URL=mysql://u:p@db.internal/mezzanine'
  addbase hidden_url            0 DB_HOST=localhost 'DB_URL=mysql://u:p@db.internal/mezzanine'
  addbase url_query_host        0 'DB_URL=mysql://u:p@localhost/mezzanine?host=db.internal'
  addbase falsy_ca_null         0 DB_HOST=db.internal MYSQL_ATTR_SSL_CA=null
  addbase falsy_ca_zero         0 DB_HOST=db.internal MYSQL_ATTR_SSL_CA=0
  addbase falsy_ca_empty        0 DB_HOST=db.internal 'MYSQL_ATTR_SSL_CA='
  addbase socket_false          0 DB_HOST=db.internal DB_SOCKET=false
  addbase cache_array           0 DB_HOST=localhost CACHE_STORE=array
  addbase cache_null            0 DB_HOST=localhost CACHE_STORE=null
  addbase cache_paren_null      0 DB_HOST=localhost 'CACHE_STORE=(NULL)'
  # The app is on this host, or has a CA: A5 must NOT refuse any of these.
  addbase loopback_host         1 DB_HOST=localhost
  addbase loopback_ipv4         1 DB_HOST=127.0.0.1
  addbase loopback_ipv6         1 DB_HOST=::1
  addbase default_host          1 DB_PASSWORD=s3cret
  addbase socket                1 DB_SOCKET=/run/mysqld/mysqld.sock DB_HOST=db.internal
  addbase url_loopback          1 'DB_URL=mysql://u:p@127.0.0.1/mezzanine'
  addbase remote_with_ca        1 DB_HOST=db.internal MYSQL_ATTR_SSL_CA=/etc/ssl/ca.crt
  addbase remote_ca_off         1 DB_HOST=db.internal MYSQL_ATTR_SSL_CA=off
}

keyof() { # keyof LINE — the key a line defines, near enough to drop a head line it overrides
  local k="$1"
  k="${k#"${k%%[![:space:]]*}"}"
  k="${k%%=*}"
  k="${k#export }"
  k="${k//\"/}"; k="${k//\'/}"
  printf '%s' "$k"
}

# ── writing one fixture ───────────────────────────────────────────────────────────────────────────────
# scope `all` puts TERM after every line; scope <i> puts it after line i alone and `\n` after the rest —
# that is the lone-`\r` shape that hid DB_HOST. `final` 0 drops the last terminator entirely: a `.env` a
# text editor left without a trailing newline is an ordinary file and its own axis.
write_fixture() { # write_fixture PATH SCOPE TERM-BYTES FINAL ; lines in FIXTURE_LINES[]
  local path="$1" scope="$2" t="$3" final="$4" i last term line
  last=$(( ${#FIXTURE_LINES[@]} - 1 ))
  : > "$path"
  for (( i = 0; i <= last; i++ )); do
    if [ "$scope" = all ]; then term="$t"; elif [ "$i" = "$scope" ]; then term="$t"; else term=$'\n'; fi
    [ "$i" != "$last" ] || [ "$final" = 1 ] || term=""
    line="${FIXTURE_LINES[i]}"
    {
      case "$line" in
        *@NUL@*) printf '%s' "${line%%@NUL@*}"; printf '\0'; printf '%s' "${line#*@NUL@}" ;;
        *)       printf '%s' "$line" ;;
      esac
      printf '%s' "$term"
    } >> "$path"
  done
}

# ── generating a population ───────────────────────────────────────────────────────────────────────────
# Per base: every terminator applied to the WHOLE file, plus every non-`\n` terminator applied to exactly
# one line's end — over the terminator of the last head line (the boundary the round-4 blocker sat on)
# through the last line — each crossed with the final terminator present and absent. The head's interior
# is covered by the whole-file axes. The count is whatever that comes to; it is counted, not declared.
CELL_DIR=(); CELL_BASE=(); CELL_AXIS=(); CELL_STRICT=()
generate() { # generate MODE
  local mode="$1" base name strict i j scope final n=0 dir
  local -a parts own
  CELL_DIR=(); CELL_BASE=(); CELL_AXIS=(); CELL_STRICT=()
  mkdir -p "$WORK/$mode"
  for base in "${BASES[@]}"; do
    IFS=$'\x1f' read -r -a parts <<< "$base"
    name="${parts[0]}"; strict="${parts[1]}"; own=("${parts[@]:2}")
    # the head, less any line this base redefines
    FIXTURE_LINES=()
    local head_line hk skip
    for head_line in "${HEAD_LINES[@]}"; do
      skip=0
      hk="$(keyof "$head_line")"
      for i in "${!own[@]}"; do
        [ "$(keyof "${own[i]}")" != "$hk" ] || { skip=1; break; }
      done
      [ "$skip" = 1 ] || FIXTURE_LINES+=("$head_line")
    done
    local first_own=${#FIXTURE_LINES[@]}
    FIXTURE_LINES+=("${own[@]}")
    local last=$(( ${#FIXTURE_LINES[@]} - 1 ))
    for final in 1 0; do
      for j in "${!TERM_BYTES[@]}"; do
        dir="$WORK/$mode/$n"; mkdir -p "$dir"
        write_fixture "$dir/.env" all "${TERM_BYTES[j]}" "$final"
        CELL_DIR+=("$dir"); CELL_BASE+=("$name"); CELL_STRICT+=("$strict")
        CELL_AXIS+=("all:${TERM_NAMES[j]}$([ "$final" = 1 ] && printf '' || printf '+noeol')")
        n=$((n + 1))
      done
      for (( scope = first_own - 1; scope <= last; scope++ )); do
        [ "$scope" -ge 0 ] || continue
        # the last line's terminator IS the final one: with `final` 0 there is nothing to vary there.
        [ "$scope" != "$last" ] || [ "$final" = 1 ] || continue
        for j in "${!TERM_BYTES[@]}"; do
          [ "$j" != "$LF_INDEX" ] || continue   # `\n` at one position IS the baseline
          dir="$WORK/$mode/$n"; mkdir -p "$dir"
          write_fixture "$dir/.env" "$scope" "${TERM_BYTES[j]}" "$final"
          CELL_DIR+=("$dir"); CELL_BASE+=("$name"); CELL_STRICT+=("$strict")
          CELL_AXIS+=("at$scope:${TERM_NAMES[j]}$([ "$final" = 1 ] && printf '' || printf '+noeol')")
          n=$((n + 1))
        done
      done
    done
  done
}

# ── running the two sides ─────────────────────────────────────────────────────────────────────────────
run_oracle() { # run_oracle MODE ORACLE-PATH → fills ORACLE_OUT[]
  local mode="$1" oracle="$2" out i
  mapfile -t ORACLE_OUT < <(printf '%s\n' "${CELL_DIR[@]}" | php "$oracle" "$mode" "${KEYS[@]}")
  [ "${#ORACLE_OUT[@]}" = "${#CELL_DIR[@]}" ] \
    || die "the oracle answered ${#ORACLE_OUT[@]} of ${#CELL_DIR[@]} fixtures — see its message above"
  for i in "${!CELL_DIR[@]}"; do
    [ "${ORACLE_OUT[i]%%$'\t'*}" = "${CELL_DIR[i]}" ] || die "the oracle's answers came back out of order at cell $i"
    ORACLE_OUT[i]="${ORACLE_OUT[i]#*$'\t'}"
  done
}

run_mirror() { # run_mirror MODE DEPLOY-PATH → fills MIRROR_OUT[]
  local mode="$1" deploy="$2" i
  mapfile -t MIRROR_OUT < <(printf '%s\n' "${CELL_DIR[@]}" | bash "$MIRROR" "$deploy" "$mode" "${KEYS[@]}")
  [ "${#MIRROR_OUT[@]}" = "${#CELL_DIR[@]}" ] \
    || die "the mirror answered ${#MIRROR_OUT[@]} of ${#CELL_DIR[@]} fixtures — see its message above"
  for i in "${!CELL_DIR[@]}"; do
    [ "${MIRROR_OUT[i]%%$'\t'*}" = "${CELL_DIR[i]}" ] || die "the mirror's answers came back out of order at cell $i"
    MIRROR_OUT[i]="${MIRROR_OUT[i]#*$'\t'}"
  done
}

# ── classifying one cell ──────────────────────────────────────────────────────────────────────────────
# CELL_CLASS is one of: ok · DANGEROUS · STRICT-REFUSAL · narrowing. Only the first two decide the exit
# status; `narrowing` is deploy.sh refusing a file phpdotenv reads, which it declares it does and which
# costs an operator a refusal, never a plaintext credential.
# It SETS CLASS and WHY rather than printing them: at a population in the hundreds, re-read once per
# control, a `$(…)` around this would be a fork per cell and most of the harness's wall clock.
classify() { # classify MODE INDEX ORACLE-FIELDS MIRROR-FIELDS → sets CLASS and WHY
  local mode="$1" i="$2" o="$3" m="$4"
  local -a of mf
  IFS=$'\t' read -r -a of <<< "$o"
  IFS=$'\t' read -r -a mf <<< "$m"
  CLASS=ok; WHY=""

  if [ "$mode" = scan ]; then
    local parse="${of[0]}" scan="${mf[0]}" k
    if [ "$parse" = reject ]; then
      [ "$scan" != accept ] || { CLASS=DANGEROUS; WHY='phpdotenv REJECTS this file (Laravel boots on nothing) and the scan ACCEPTS it'; }
      return
    fi
    if [ "$scan" = refuse ]; then
      if [ "${CELL_STRICT[i]}" = 1 ]; then CLASS=STRICT-REFUSAL; WHY='the scan refuses a file the app boots on'
      else CLASS=narrowing; WHY='the scan refuses a file phpdotenv reads'; fi
      return
    fi
    for k in "${!KEYS[@]}"; do
      local ov="${of[k+1]}" mv="${mf[k+1]}"
      if [ "$mv" = unread ]; then
        [ "${CELL_STRICT[i]}" != 1 ] || { CLASS=STRICT-REFUSAL; WHY="${KEYS[k]} is unreadable to the script in a file the app boots on"; return; }
        continue
      fi
      [ "$ov" = "$mv" ] || { CLASS=DANGEROUS; WHY="${KEYS[k]}: phpdotenv holds $ov, the script reads $mv"; return; }
    done
    return
  fi

  local boot="${of[0]}" store="${of[1]}" ca="${of[2]}" cache="${of[3]}" verdict="${mf[0]}"
  if [ "$boot" = bootfail ]; then
    [ "$verdict" != ok ] || { CLASS=DANGEROUS; WHY='Laravel cannot boot on this file and A5 says ok'; }
    return
  fi
  local unsafe=0 why=""
  if [ "$store" = remote ] && [ "$ca" = none ]; then unsafe=1; why="the app connects to another host with no CA"; fi
  case "$cache" in array | null) unsafe=1; why="${why:+$why, and }the app's cache driver is '$cache', which keeps nothing" ;; esac
  if [ "$unsafe" = 1 ]; then
    [ "$verdict" != ok ] || { CLASS=DANGEROUS; WHY="$why — A5 says ok"; }
    return
  fi
  if [ "$verdict" != ok ]; then
    if [ "${CELL_STRICT[i]}" = 1 ]; then CLASS=STRICT-REFUSAL; WHY="A5 $verdict a file whose app is on this host (store=$store ca=$ca cache=$cache)"
    else CLASS=narrowing; WHY="A5 $verdict (store=$store ca=$ca cache=$cache)"; fi
  fi
}

# ── one differential run ──────────────────────────────────────────────────────────────────────────────
# Fills DANGEROUS_ROWS[] and the four counters. The oracle's answer depends on the fixture alone, never on
# deploy.sh, so it is computed once per population and reused for every mutant — which is what makes the
# controls cost bash rather than a second pass of PHP.
diff_run() { # diff_run MODE — needs ORACLE_OUT and MIRROR_OUT filled
  local mode="$1" i
  N_OK=0; N_DANGEROUS=0; N_STRICT=0; N_NARROW=0; DANGEROUS_ROWS=()
  for i in "${!CELL_DIR[@]}"; do
    classify "$mode" "$i" "${ORACLE_OUT[i]}" "${MIRROR_OUT[i]}"
    case "$CLASS" in
      DANGEROUS)      N_DANGEROUS=$((N_DANGEROUS + 1)) ;;
      STRICT-REFUSAL) N_STRICT=$((N_STRICT + 1)) ;;
      narrowing)      N_NARROW=$((N_NARROW + 1)) ;;
      *)              N_OK=$((N_OK + 1)); [ "$VERBOSE" = 0 ] || printf '   ok   %-22s %-18s\n' "${CELL_BASE[i]}" "${CELL_AXIS[i]}"; continue ;;
    esac
    DANGEROUS_ROWS+=("$CLASS|${CELL_BASE[i]}|${CELL_AXIS[i]}|$WHY")
  done
}

print_rows() { # print_rows LIMIT CLASS-FILTER
  local limit="$1" want="$2" row shown=0 class base axis why
  for row in "${DANGEROUS_ROWS[@]}"; do
    IFS='|' read -r class base axis why <<< "$row"
    [ "$want" = any ] || [ "$class" = "$want" ] || continue
    [ "$shown" -lt "$limit" ] || { printf '   … and more\n'; return; }
    printf '   %-15s %-22s %-18s %s\n' "$class" "$base" "$axis" "$why"
    shown=$((shown + 1))
  done
}

# ── the controls ──────────────────────────────────────────────────────────────────────────────────────
# Each mutant is ONE defect, applied by sed to a COPY of deploy.sh (this script never writes bin/deploy.sh),
# and each is a defect this repo has shipped or came within a review round of shipping. A sed that matches
# nothing is itself a failure: it would leave an unmutated copy that reports zero dangerous cells and read
# as "the control did not discriminate" for the wrong reason, so the copy is required to differ.
mutant() { # mutant NAME SED-EXPR → prints the mutant's path
  local name="$1" expr="$2" path="$WORK/mutants/$name.sh"
  mkdir -p "$WORK/mutants"
  cp "$DEPLOY" "$path"
  sed -i "$expr" "$path"
  cmp -s "$DEPLOY" "$path" && die "control '$name' did not change $DEPLOY — its sed matched nothing, so the code it exists to mutate has moved"
  printf '%s' "$path"
}

# A control asks ONE question — can this differential report a dangerous cell at all — so it stops at the
# FIRST one and names it. It deliberately does not count them: a mutant's dangerous-cell total is a number
# about a file that exists only inside this run, and running the whole population to compute it would cost
# a full pass per control on every PR for a figure nothing decides on.
control() { # control MODE NAME DESCRIPTION SED-EXPR
  local mode="$1" name="$2" desc="$3" expr="$4" path line i=0 found=""
  path="$(mutant "$name" "$expr")" || exit 2
  while IFS= read -r line; do
    [ "${line%%$'\t'*}" = "${CELL_DIR[i]}" ] || die "the mirror's answers came back out of order at cell $i of control '$name'"
    classify "$mode" "$i" "${ORACLE_OUT[i]}" "${line#*$'\t'}"
    [ "$CLASS" != DANGEROUS ] || { found="${CELL_BASE[i]}|${CELL_AXIS[i]}|$WHY"; break; }
    i=$((i + 1))
  done < <(printf '%s\n' "${CELL_DIR[@]}" | bash "$MIRROR" "$path" "$mode" "${KEYS[@]}")

  if [ -n "$found" ]; then
    local base axis why
    IFS='|' read -r base axis why <<< "$found"
    printf '   seen to fail  %-16s %s\n' "$name" "$desc"
    printf '                 → DANGEROUS at cell %d: %s %s — %s\n' "$i" "$base" "$axis" "$why"
  else
    fail "control '$name' ($desc) did NOT red the $mode differential over any of its ${#CELL_DIR[@]} cells. A differential that cannot report a dangerous cell is a decoration; either the mutation no longer expresses that defect, or the population no longer contains a fixture that exposes it."
  fi
}

# ── the oracle's own control ──────────────────────────────────────────────────────────────────────────
# The oracle runs the whole population in one process, so its load-bearing claim is that no fixture can see
# another's values. That is checked by running the population again in REVERSE and requiring every cell to
# answer identically — and the check is shown to discriminate by mutating the oracle to reuse one
# repository across fixtures, which an order-independent oracle cannot survive.
oracle_order_check() { # oracle_order_check MODE ORACLE-PATH → 0 when order-independent
  local mode="$1" oracle="$2" i n disagree=0
  local -a rev
  mapfile -t rev < <(printf '%s\n' "${CELL_DIR[@]}" | tac | php "$oracle" "$mode" "${KEYS[@]}" | tac)
  [ "${#rev[@]}" = "${#CELL_DIR[@]}" ] || return 1
  for i in "${!CELL_DIR[@]}"; do
    [ "${rev[i]#*$'\t'}" = "${ORACLE_OUT[i]}" ] || disagree=$((disagree + 1))
  done
  [ "$disagree" = 0 ]
}

run_oracle_controls() { # run_oracle_controls MODE
  local mode="$1" leaky="$WORK/mutants/oracle-leak.php"
  mkdir -p "$WORK/mutants"
  if oracle_order_check "$mode" "$ORACLE"; then
    say "   the oracle answers every cell the same in reverse order — no fixture sees another's values"
  else
    fail "the oracle's answers depend on the ORDER the population is fed to it, so its per-fixture isolation is broken and every cell below is suspect"
  fi
  cp "$ORACLE" "$leaky"
  sed -i 's|^    \$repository = freshRepository();$|    static $leak = null; $leak ??= freshRepository(); $repository = $leak;|' "$leaky"
  cmp -s "$ORACLE" "$leaky" && die "the oracle-leak control's sed matched nothing — env-mirror-diff.oracle.php's per-fixture repository has moved"
  if oracle_order_check "$mode" "$leaky"; then
    fail "the oracle-leak control did NOT red the order check: an oracle deliberately sharing one repository across fixtures went undetected, so that check proves nothing"
  else
    say "   seen to fail  oracle-leak      one repository shared across fixtures → the order check reds"
  fi
}

# ── the scan differential ─────────────────────────────────────────────────────────────────────────────
POP_SCAN=0; POP_LOCALITY=0

do_scan() {
  head2 "SCAN — env_file_scan / env_lines_load / env_get  vs  Dotenv\\Parser\\Parser"
  scan_bases
  generate scan
  POP_SCAN=${#CELL_DIR[@]}
  say "   population: $POP_SCAN cells — ${#BASES[@]} bases × the axes derived from Parser::parse's split pattern ($SPLIT_PATTERN)"
  say "   keys compared, read out of $DEPLOY's own env_read call sites: ${KEYS[*]}"
  run_oracle scan "$ORACLE"
  run_oracle_controls scan
  run_mirror scan "$DEPLOY"
  diff_run scan
  printf '   %d ok · %d declared narrowings · %d DANGEROUS · %d refusals of a file the app boots on\n' \
    "$N_OK" "$N_NARROW" "$N_DANGEROUS" "$N_STRICT"
  if [ "$N_DANGEROUS" -gt 0 ] || [ "$N_STRICT" -gt 0 ]; then
    print_rows 40 DANGEROUS
    print_rows 40 STRICT-REFUSAL
    fail "the scan differential found $N_DANGEROUS dangerous and $N_STRICT wrongly-refused cells"
  fi
  [ "$VERBOSE" = 0 ] || print_rows 400 narrowing

  head2 'SCAN controls — the differential, seen to fail'
  control scan lf-only 'env_lines_load splits on \n alone (card#9561 r4)' \
    '/^  content="\${content\/\//d'
  control scan no-scan 'env_file_scan certifies every file' \
    's|^env_file_scan() {$|env_file_scan() { return 0|'
  control scan nul-blind 'the NUL refusal is cut out' \
    's|^  if \[ "\$ENV_LINES_NUL" = 1 \]; then$|  if false; then|'
}

do_locality() {
  head2 'LOCALITY — A5’s store verdict  vs  where Laravel really connects'
  locality_bases
  generate locality
  POP_LOCALITY=${#CELL_DIR[@]}
  say "   population: $POP_LOCALITY cells — ${#BASES[@]} bases × the same derived axes"
  run_oracle locality "$ORACLE"
  run_oracle_controls locality
  run_mirror locality "$DEPLOY"
  diff_run locality
  printf '   %d ok · %d declared narrowings · %d DANGEROUS · %d refusals of a file whose app is on this host\n' \
    "$N_OK" "$N_NARROW" "$N_DANGEROUS" "$N_STRICT"
  if [ "$N_DANGEROUS" -gt 0 ] || [ "$N_STRICT" -gt 0 ]; then
    print_rows 40 DANGEROUS
    print_rows 40 STRICT-REFUSAL
    fail "the locality differential found $N_DANGEROUS dangerous and $N_STRICT wrongly-refused cells"
  fi
  [ "$VERBOSE" = 0 ] || print_rows 400 narrowing

  head2 'LOCALITY controls — the differential, seen to fail'
  control locality lf-only 'env_lines_load splits on \n alone (card#9561 r4)' \
    '/^  content="\${content\/\//d'
  control locality ca-text 'the CA is judged by its TEXT, not by the value the app receives' \
    's|^env_app_falsy() {$|env_app_falsy() { return 1;|'
  control locality url-blind 'store_locality stops reading DB_URL' \
    's|^  env_read url DB_URL .*$|  url=""|'
  control locality socket-text 'any DB_SOCKET text counts as a socket' \
    's|^  if \[ "\${socket:0:1}" = "/" \]; then$|  if [ -n "$socket" ]; then|'
}

# ── run ───────────────────────────────────────────────────────────────────────────────────────────────
printf '\nenv-mirror-diff.sh — %s\n' "$DEPLOY"
printf 'oracle: %s (phpdotenv %s)\n' "$REPO/server/vendor" \
  "$(sed -n 's/.*"version": "\(v[0-9.]*\)".*/\1/p' <<< "$(grep -A2 '"name": "vlucas/phpdotenv"' "$REPO/server/composer.lock")" | head -1)"
head2 'the loopback set — two statements, held together'
check_loopback_sets

case "$ONLY" in
  both)     do_scan; do_locality ;;
  scan)     do_scan ;;
  locality) do_locality ;;
  *)        die "--only takes scan, locality or both" ;;
esac

printf '\n──────────────────────────────────────────────\n'
printf 'population covered this run: %d scan cells + %d locality cells = %d\n' \
  "$POP_SCAN" "$POP_LOCALITY" "$((POP_SCAN + POP_LOCALITY))"
if [ "$FAILURES" = 0 ]; then
  printf 'env-mirror-diff.sh: the mirror agrees with the parser, and every control red when mutated\n'
  exit 0
fi
printf 'env-mirror-diff.sh: %d FAILURES\n' "$FAILURES" >&2
exit 1

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
# WHAT THE POPULATION IS DERIVED FROM, AND WHAT IS WRITTEN DOWN. Both AXES are derived per run rather
# than listed here, and they are held to their sources by DIFFERENT strengths — the block below says which
# is which. The line-ending axis is read out of BOTH `Dotenv\Parser\Parser::parse`'s own
# split regex AND `env_lines_load`'s own normalisation statements, held against each other in both
# directions; the keys compared are read out of the THREE idioms that read a key from `.env` — deploy.sh's
# `env_read VAR KEY` sites, its literal-key `env_get KEY` sites, and `server/.env.example`'s key list,
# which A10b loops `env_get` over — and held against a floor derived from A5's own refusal text. The cell
# count is COUNTED as the run emits cells and printed at the end; no population size is stated, because a
# written count becomes a quoted authority that outlives the run that falsified it.
#
# WHAT A GREEN RUN DOES NOT PROVE, because "derived on every run" is not "cannot narrow". A green run
# proves the mirror and the parser agree over the population THIS RUN derived, and that every control red
# when its defect was introduced. It does not prove the population is everything it should cover:
#
#   · the `.env.example` leg is a SECOND TYPING of A10b's own key grep, over the WORKING TREE's copy where
#     A10b reads the TARGET release's — two lists that agree by inspection, with no check binding them;
#   · the FLOOR reaches only the refused keys no other leg covers — today MYSQL_ATTR_SSL_CA alone — so a
#     key read only through `env_read` and named by no refusal, `DB_SOCKET` being one, can drop out of the
#     compared keys with this script green.
#
# Both are stated again where they are derived, with the commands that re-measure them. Neither is an
# oversight: card#9591 records both as won't-do, with the measurement that sized them. Knowing that a
# SECOND guard over the key population is the thing to build if this ever costs something, rather than
# believing the derivation is total, is what this paragraph is for.
#
# The fixture BASES are the half that IS written down: `scan_bases` and `locality_bases` are a
# hand-maintained enumeration of `.env` shapes, and they are precisely the half that must GROW when a new
# shape is found. This PR's own `lf-only` control is the worked example — it could not red the locality
# differential until a comment line was added above each base's payload. A shape nobody has thought of is
# not in here; adding it is an edit to those two functions, and that is the intended way to extend this.
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
# and never reads or writes the real `server/.env` — env-mirror-diff.mirror.sh PRINTS the values it
# reads, so it is bounded to that temp dir through `MEZZ_ENV_MIRROR_WORK`, exported here and checked
# there. The bound's own refusal is watched on every run (`the mirror’s fixture root`, below).
#
# EXIT 0 no dangerous cells and every control red when mutated · 1 a dangerous cell, a refusal of a file
# the app boots on where none is declared, a terminator set that has drifted, or a control that failed to
# discriminate · 2 the harness could not run or would have compared less than it claims (no vendor tree, no
# pdo_mysql, no server/.env.example, deploy.sh's reader block moved, a terminator that is not a CR/LF
# sequence, or a key A5 refuses by name that the key derivation no longer covers).

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
# Every fixture is generated under here, and this is also the ROOT env-mirror-diff.mirror.sh is bounded
# to: that script prints `.env` values, so the directories it may open are the ones this run wrote.
export MEZZ_ENV_MIRROR_WORK="$WORK"

FAILURES=0
fail() { printf '   ⛔ %s\n' "$*"; FAILURES=$((FAILURES + 1)); }

# ── the line terminators: stated twice, derived from BOTH, held together ──────────────────────────────
# What "a LINE" is gets stated twice, once by each side this differential compares:
#
#   · `Dotenv\Parser\Parser::parse` splits on `Regex::split("/(\r\n|\n|\r)/")`;
#   · `env_lines_load` rewrites each terminator to `\n` with its own `content="${content//$'…'/$'\n'}"`
#     statements, and then splits on `\n`.
#
# BOTH are READ, and held against each other in BOTH directions, for exactly the reason the loopback set
# below is. Deriving the axis from the PARSER ALONE is an OPTIMISTIC derivation: it dies on a terminator
# the parser ADDS and is silent when it NARROWS. phpdotenv v6 moving to `/(\r\n|\n)/` would delete the
# lone-`\r` axis — no `\r` fixture written at any position, this harness green — while `env_lines_load`
# went on splitting on `\r`, so a host `.env` holding `DB_HOST=localhost\rMYSQL_ATTR_SSL_CA=/etc/ssl/ca.crt`
# would be TWO lines to deploy.sh (loopback → A5 ok, TLS not required) and ONE to Dotenv (remote, and the
# CA never defined). That is card#9561 round 4 with the arrow reversed. Deriving it from deploy.sh alone
# has the mirror-image hole.
#
# So a difference EITHER WAY reds, and the fixture axis is the UNION of the two sets: the terminator one
# side has stopped honouring is precisely the one whose fixtures must still be written, because those are
# the cells that report the divergence rather than merely asserting it.
PARSER="$REPO/server/vendor/vlucas/phpdotenv/src/Parser/Parser.php"
[ -f "$PARSER" ] || die "no $PARSER — run \`composer install\` in server/. The vendored parser IS the oracle."

parser_terms() { # parser_terms PARSER.php — Parser::parse's terminators, one escaped form per line
  sed -n 's|.*Regex::split("/(\(.*\))/".*|\1|p' "$1" | tr '|' '\n' | grep -v '^$'
}

deploy_terms() { # deploy_terms DEPLOY — the terminators env_lines_load treats as ending a line
  # `while IFS= read -r line` splits on `\n`, so `\n` ends a line whatever the rewrites above it say. Each
  # `content="${content//$'X'/$'\n'}"` adds X. The REPLACEMENT is part of what is matched on purpose: a
  # statement rewriting X to anything other than `\n` does not make X end a line and must not be counted,
  # and the drift check then reds for the terminator that went missing rather than inventing one.
  local q="'"
  printf '%s\n' '\n'
  grep -oE "content//\\\$${q}[^${q}]*${q}/\\\$${q}\\\\n${q}\}" "$1" \
    | sed "s|^content//\\\$${q}||; s|${q}/.*\$||"
}

terms_key() { # terms_key TERM… — a set of terminators as one comparable string
  printf '%s\n' "$@" | sort -u | tr '\n' ' '
}

terminator_drift() { # terminator_drift DEPLOY PARSER — prints the drift, and nothing when the two agree
  local -a p d
  mapfile -t p < <(parser_terms "$2")
  mapfile -t d < <(deploy_terms "$1")
  if [ "${#p[@]}" = 0 ] || [ "${#d[@]}" -lt 2 ]; then
    printf 'one side names no terminator at all — parser: %d, env_lines_load: %d' "${#p[@]}" "$(( ${#d[@]} - 1 ))"
    return 0
  fi
  local from_parser from_deploy
  from_parser="$(terms_key "${p[@]}")"
  from_deploy="$(terms_key "${d[@]}")"
  [ "$from_parser" = "$from_deploy" ] || printf 'env_lines_load: [%s] Parser::parse: [%s]' "$from_deploy" "$from_parser"
}

SPLIT_PATTERN="$(sed -n 's|.*Regex::split("/(\(.*\))/".*|\1|p' "$PARSER")"
[ -n "$SPLIT_PATTERN" ] || die "could not read Parser::parse's split pattern out of $PARSER"

TERM_NAMES=(); TERM_BYTES=(); LF_INDEX=-1
{
  # The parser's order first — it is the order a reader of Parser.php expects — then anything only
  # deploy.sh calls a terminator, appended. `sort -u` is not used for the merge: it would reorder the
  # axis names on every run for no gain.
  mapfile -t _p < <(parser_terms "$PARSER")
  mapfile -t _d < <(deploy_terms "$DEPLOY")
  [ "${#_p[@]}" -gt 0 ] || die "Parser::parse's split pattern named no terminator at all in $PARSER"
  [ "${#_d[@]}" -gt 1 ] || die "env_lines_load in $DEPLOY normalises no line terminator at all — the \`content=\"\${content//\$'…'/\$'\\n'}\"\` statements this harness reads its half of the axis from have moved or been renamed"
  for _alt in "${_p[@]}" "${_d[@]}"; do
    _rest="${_alt//\\r/}"; _rest="${_rest//\\n/}"
    [ -z "$_rest" ] || die "'$_alt' is named as a line terminator by Parser::parse or by env_lines_load and is not a CR/LF sequence — this harness's line-ending axis no longer describes them"
    _name="${_alt//\\/}"
    case "$_name" in rn) _name=crlf ;; n) _name=lf ;; r) _name=cr ;; esac
    case " ${TERM_NAMES[*]} " in *" $_name "*) continue ;; esac
    _bytes="${_alt//\\r/$'\r'}"; _bytes="${_bytes//\\n/$'\n'}"
    TERM_NAMES+=("$_name"); TERM_BYTES+=("$_bytes")
    [ "$_bytes" != $'\n' ] || LF_INDEX=$((${#TERM_NAMES[@]} - 1))
  done
}
[ "$LF_INDEX" -ge 0 ] || die "neither Parser::parse's split pattern nor env_lines_load's normalisation includes a bare \\n; this harness writes its baseline fixtures with one"

# ── the keys compared: three idioms, unioned, held against a floor ────────────────────────────────────
# THREE idioms read a key out of `.env`, and the population is their UNION. One of them alone is not it:
#
#   · `env_read VAR KEY` — A5's idiom, and the only one the first round of this harness covered;
#   · a literal-key `env_get KEY` — `url="$(env_get APP_URL)"` is phase B's ONLY smoke check, and every
#     fixture here writes an APP_URL. A divergence on it either drops the deploy's one verification ("The
#     deploy is UNVERIFIED") or smoke-checks a URL the app does not have, with the window already CLOSED
#     and the new release serving;
#   · `server/.env.example`'s key list — A10b builds it at run time from the TARGET release's copy and
#     reads every key of it through `env_get "$k"`. A divergence on one of those keys makes A10b tell an
#     operator their host does not set a key it does set, or stay silent about one it does not.
#     ⚠ WHAT THIS LEG IS, EXACTLY: a SECOND TYPING of A10b's `grep -Eo '^[A-Z][A-Z0-9_]*='`, and nothing
#     holds the two spellings together. They agree today by inspection — `derive_keys` below and
#     bin/deploy.sh's A10b, read side by side — not by construction, and the two also read DIFFERENT
#     COPIES of the file: A10b reads the TARGET release's through `git_read_at`, this reads the working
#     tree's. So a change to either regex, or a `.env.example` whose committed copy differs from the
#     checked-out one, narrows or widens this leg silently. Not an oversight: card#9591 records it as a
#     won't-do, with the measurement. Binding the two would take a derivation A10b PUBLISHES and this
#     reads — the same shape ENV_LOOPBACK_HOSTS uses — and that is the fix if this leg ever matters more.
#
# AND A FLOOR, because a derivation over call sites cannot notice that it has NARROWED: nothing above
# would report a refactor that routed the `env_read` sites through a new helper, and the harness would run
# green over whatever keys the new idiom hid. So the union is held against a set derived from a surface
# none of the three idioms touches — the keys A5 REFUSES BY NAME in its own refusal text — and a key that
# drops out of the union while A5 still refuses on it stops this script.
#
# ⚠ WHAT THE FLOOR ACTUALLY DISCRIMINATES, because it is less than "a narrowing reds". Its reach is the
# refused keys NO OTHER LEG COVERS: a refused key that `.env.example` also names survives any refactor of
# the call sites through the third leg, so losing it from the `env_read` leg reds nothing. Re-derive the
# two sets rather than trust this sentence —
#     comm -23 <(grep -oE 'refuse "[A-Z][A-Z0-9_]+ is ' bin/deploy.sh | awk '{print $2}' | tr -d '"' | sort -u) \
#              <(grep -Eo '^[A-Z][A-Z0-9_]*=' server/.env.example | tr -d '=' | sort -u)
# — and today that prints MYSQL_ATTR_SSL_CA alone, which is why the control below renames every
# `env_read` site: that is the one rename the floor can see. AND THE CONVERSE, which is the real gap: a
# key read ONLY through `env_read` and named by NO refusal has no floor at all. `DB_SOCKET` is one, today
# and measurably — rename every `env_read` site EXCEPT the MYSQL_ATTR_SSL_CA one and this harness runs
# green with DB_SOCKET quietly gone from the compared keys, while A5's store verdict still turns on it.
# A floor read out of REFUSAL TEXT cannot cover it: `DB_SOCKET` feeds `store_locality`, whose refusal is
# ABOUT the CA — `MYSQL_ATTR_SSL_CA is unset for a store on another host` — and names DB_SOCKET and
# DB_HOST only in the remedy lines under it, which are not the `refuse "KEY is …"` subject
# `derive_floor_keys` reads. That is a recorded decision and not an oversight — card#9591 holds the
# won't-do and the measurement. What would close it is a surface naming the keys A5's verdict DEPENDS on,
# which neither side states today.
ENV_EXAMPLE="$REPO/server/.env.example"
[ -f "$ENV_EXAMPLE" ] || die "no $ENV_EXAMPLE — bin/deploy.sh's A10b reads every key of it through env_get, so it is one of the three idioms this harness's key population is derived from"

derive_keys() { # derive_keys DEPLOY — the union of the three idioms, one key per line
  {
    grep -oE 'env_read [a-z_]+ [A-Z_][A-Z0-9_]*' "$1" | awk '{print $3}'
    # Comment lines are struck out BEFORE the match: deploy.sh's own prose writes `env_get KEY` as the
    # function's signature, and a signature in a comment is not a call site.
    sed 's/^[[:space:]]*#.*//' "$1" | grep -oE 'env_get "?[A-Z_][A-Z0-9_]*"?' | sed 's/^env_get "\?//; s/"$//'
    grep -Eo '^[A-Z][A-Z0-9_]*=' "$ENV_EXAMPLE" | tr -d '='
  } | sort -u
}

derive_floor_keys() { # derive_floor_keys DEPLOY — the keys A5 refuses BY NAME, out of its refusal text
  # `refuse "KEY is …"` is A5's own sentence about a key of `.env`. It is a DIFFERENT surface from every
  # idiom above, which is the whole point: a refactor of the call sites does not touch it. A refusal about
  # something that is not a `.env` key but is phrased that way would ADD a key here and red loudly — the
  # safe direction for a floor, which exists to catch a SHRINK.
  grep -oE 'refuse "[A-Z][A-Z0-9_]+ is ' "$1" | awk '{print $2}' | tr -d '"' | sort -u
}

uncovered_floor_keys() { # uncovered_floor_keys DEPLOY — the floor keys its derived union does not cover
  local k derived
  local -a floor
  derived=" $(derive_keys "$1" | tr '\n' ' ')"
  mapfile -t floor < <(derive_floor_keys "$1")
  for k in "${floor[@]}"; do
    case "$derived" in *" $k "*) ;; *) printf '%s ' "$k" ;; esac
  done
}

mapfile -t KEYS < <(derive_keys "$DEPLOY")
[ "${#KEYS[@]}" -gt 0 ] || die "no key at all was derived from $DEPLOY's \`env_read VAR KEY\` sites, its literal-key \`env_get KEY\` sites or $ENV_EXAMPLE — the readers have been renamed and this harness would compare nothing"

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
  # ⚠ TO WHOEVER WIDENS THIS SET: a member bearing a SPACE would transport wrong and this check would
  # not say so. Both sides are carried here space-joined and split again with `read -a`, so `[::1] x` would
  # arrive as two members for the URL decider in env-mirror-diff.oracle.php and for the compare below —
  # which would agree, on the wrong set — while deploy.sh's `host_locality` compares the array element
  # whole and would decide on the one-member spelling. Unreachable today: every member of both statements
  # is a host name or literal. A space-bearing member needs a transport that is not space-joined.
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
  # shellcheck disable=SC2016  # the fixture must hold ${DB_DATABASE} as TEXT: whether phpdotenv
  # interpolates it and whether the mirror does the same is exactly what this base measures.
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
# The two raw rows are `orow`/`mrow` and not `o`/`m` ON PURPOSE, and renaming them back re-mints two
# findings: ShellCheck has one namespace per FILE, so a scalar `o` here and check_loopback_sets's
# unrelated `local -a o` are one variable to it, and it reported this function destructuring an array
# without an index — the shape that silently keeps only the first element. Bash does not agree (measured:
# a scalar `local o=` called from inside a frame holding `local -a o` is still `declare --`, and the
# read below still yields every field), so a disable here would have pinned a FALSE claim and blinded
# these two lines to a real SC2178 later. Distinct names make the analyser's model true instead.
classify() { # classify MODE INDEX ORACLE-FIELDS MIRROR-FIELDS → sets CLASS and WHY
  local mode="$1" i="$2" orow="$3" mrow="$4"
  local -a of mf
  IFS=$'\t' read -r -a of <<< "$orow"
  IFS=$'\t' read -r -a mf <<< "$mrow"
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
  # Two `local` statements, not one: a single `local name="$1" path="…/$name.sh"` expands `$name` BEFORE
  # this call's `local` assigns it, so the path would be built from whatever `name` the CALLER happens to
  # have (shellcheck SC2318). It worked only while every caller had a local `name` holding the same string.
  local name="$1" expr="$2"
  local path="$WORK/mutants/$name.sh"
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

# ── the two derivations, held against their second statements and seen to fail ────────────────────────
# Both of these are DRIFT CHECKS over a derivation, and a drift check nobody has watched red is the
# decoration this harness exists to refuse. Each carries its own control, and the terminator one carries
# ONE PER DIRECTION, because the failure that motivated it is the direction the first round did not have.
# Neither control runs the population: what is under test is the derivation, and re-deriving is cheap.
check_terminator_sets() {
  local drift narrowed pat_esc nar_esc mutant_parser mutant_deploy q expr
  drift="$(terminator_drift "$DEPLOY" "$PARSER")"
  if [ -z "$drift" ]; then
    say "   terminator sets agree: ${TERM_NAMES[*]} — Parser::parse's /($SPLIT_PATTERN)/ and env_lines_load's own normalisation"
  else
    fail "the line-terminator sets have DRIFTED — $drift. One side ends a line where the other does not, which is card#9561 round 4's shape; the axis below is the UNION of the two, so the cells that report it are still written."
  fi

  mkdir -p "$WORK/mutants"

  # Direction 1 — the PARSER narrows. This is phpdotenv v6 dropping the lone `\r`, the case a
  # parser-only derivation answers by generating fewer fixtures and going green.
  narrowed="${SPLIT_PATTERN%|*}"
  if [ "$narrowed" = "$SPLIT_PATTERN" ]; then
    fail "the parser-narrowed control could not be built: Parser::parse's split pattern /($SPLIT_PATTERN)/ has a single alternative, so there is nothing to drop from it. That leaves this check unwatched in the direction that motivated it."
  else
    mutant_parser="$WORK/mutants/parser-narrowed.php"
    cp "$PARSER" "$mutant_parser"
    pat_esc="${SPLIT_PATTERN//\\/\\\\}"; nar_esc="${narrowed//\\/\\\\}"
    sed -i "s@Regex::split(\"/($pat_esc)/\"@Regex::split(\"/($nar_esc)/\"@" "$mutant_parser"
    cmp -s "$PARSER" "$mutant_parser" \
      && die "the parser-narrowed control's sed matched nothing — Parser::parse's split call has moved in $PARSER"
    if [ -n "$(terminator_drift "$DEPLOY" "$mutant_parser")" ]; then
      say "   seen to fail  parser-narrowed   Parser::parse narrowed to /($narrowed)/ → the terminator check reds"
    else
      fail "the terminator check did NOT red for a Parser::parse narrowed to /($narrowed)/. A parser that stops splitting on a terminator env_lines_load still splits on is card#9561 round 4 with the arrow reversed, and this check is the only thing that reports it."
    fi
  fi

  # Direction 2 — DEPLOY.SH narrows, ONE STATEMENT AT A TIME. The `lf-only` control below deletes BOTH
  # normalisation statements and so tests only their total absence; nothing held either one alone.
  # WHICH statement is deleted is DERIVED, not written here: the last non-`\n` terminator BOTH sides
  # currently name. Naming `\r` outright would make this control stop expressing a narrowing the moment
  # the parser stopped splitting on `\r` — deleting deploy.sh's `\r` statement then makes the two sets
  # AGREE — and a control that quietly stops discriminating is what the whole round is about.
  local -a p d
  local victim="" i j
  mapfile -t p < <(parser_terms "$PARSER")
  mapfile -t d < <(deploy_terms "$DEPLOY")
  for (( i = ${#d[@]} - 1; i >= 0; i-- )); do
    [ "${d[i]}" != '\n' ] || continue
    for j in "${!p[@]}"; do
      [ "${p[j]}" != "${d[i]}" ] || { victim="${d[i]}"; break 2; }
    done
  done
  if [ -z "$victim" ]; then
    fail "the one-statement terminator control could not be built: Parser::parse and env_lines_load name no terminator in common beyond a bare \\n, so deleting one of env_lines_load's normalisation statements cannot express a narrowing. That is itself the drifted state reported above, and it leaves this check unwatched."
    return 0
  fi
  q="'"
  expr="\\@content//\\\$${q}${victim//\\/\\\\}${q}/@d"
  mutant_deploy="$(mutant terminator-half "$expr")" || exit 2
  if [ -n "$(terminator_drift "$mutant_deploy" "$PARSER")" ]; then
    say "   seen to fail  terminator-half   env_lines_load stops normalising '$victim' → the terminator check reds"
  else
    fail "the terminator check did NOT red for an env_lines_load that stopped normalising '$victim' while Parser::parse still splits on it, so it does not hold the normalisation statements against the parser one at a time."
  fi
}

# ── the mirror's fixture root, seen to refuse ─────────────────────────────────────────────────────────
# env-mirror-diff.mirror.sh PRINTS the values it reads, so which `.env` it may open is part of its
# contract: only what lies under MEZZ_ENV_MIRROR_WORK, exported above. A bound nobody has watched refuse
# is the decoration this harness exists to refuse, so it is checked in three legs — and the first leg is
# what makes the other two mean anything: the SAME fixture, with the same key in it, is ANSWERED inside
# the root. Without it "no value came out" is satisfied by a probe that never worked.
# The canary is a literal written here and read back here; no real value is ever in play, and the failure
# messages below say THAT a value came out rather than repeating it.
MIRROR_CANARY='CONFINEMENT-CANARY-NOT-A-SECRET'
check_mirror_confinement() {
  local inside outside out rc leaked
  inside="$WORK/confinement"; outside="$(mktemp -d)"
  mkdir -p "$inside"
  printf 'APP_ENV=production\nDB_PASSWORD=%s\n' "$MIRROR_CANARY" > "$inside/.env"
  cp "$inside/.env" "$outside/.env"

  out="$(printf '%s\n' "$inside" | bash "$MIRROR" "$DEPLOY" scan DB_PASSWORD 2>&1)"; rc=$?
  case "$out" in
    *"$MIRROR_CANARY"*) say '   a fixture INSIDE the root is answered, value and all — so the two refusals below are refusals' ;;
    *) fail "the mirror did not answer a fixture inside its own root (exit $rc), so the two checks below would pass on a probe that never worked: $out" ;;
  esac

  out="$(printf '%s\n' "$outside" | bash "$MIRROR" "$DEPLOY" scan DB_PASSWORD 2>&1)"; rc=$?
  leaked=0; case "$out" in *"$MIRROR_CANARY"*) leaked=1 ;; esac
  if [ "$leaked" = 1 ]; then
    fail "the mirror PRINTED a value out of a .env OUTSIDE its fixture root. It is then a general-purpose .env value printer for any directory a caller names — point it at a live host's server/ and it prints that host's secrets — which is the one thing the root exists to stop"
  elif [ "$rc" = 0 ]; then
    fail 'the mirror exited 0 for a fixture directory outside its fixture root, so it does not hold the bound its header states'
  else
    say '   seen to refuse  outside-root    a fixture directory outside MEZZ_ENV_MIRROR_WORK → the mirror dies, printing no value'
  fi

  out="$(printf '%s\n' "$inside" | env -u MEZZ_ENV_MIRROR_WORK bash "$MIRROR" "$DEPLOY" scan DB_PASSWORD 2>&1)"; rc=$?
  leaked=0; case "$out" in *"$MIRROR_CANARY"*) leaked=1 ;; esac
  if [ "$leaked" = 1 ] || [ "$rc" = 0 ]; then
    fail 'the mirror ran with MEZZ_ENV_MIRROR_WORK UNSET, so the root is optional — and a bound a caller omits by doing nothing is not a bound'
  else
    say '   seen to refuse  no-root         MEZZ_ENV_MIRROR_WORK unset → the mirror dies before reading anything'
  fi

  rm -rf "$outside"
}

check_key_derivation() {
  local uncovered mutant_deploy
  local -a floor
  mapfile -t floor < <(derive_floor_keys "$DEPLOY")
  [ "${#floor[@]}" -gt 0 ] \
    || die "no \`refuse \"KEY is …\"\` refusal was found in $DEPLOY — the floor this harness holds its key derivation against is read out of A5's own refusal text, and that text has moved. Without it a derivation that has NARROWED cannot be reported, which is the one thing the floor exists for."
  uncovered="$(uncovered_floor_keys "$DEPLOY")"
  [ -z "$uncovered" ] || die "the key derivation does not cover ${uncovered% }, which $DEPLOY REFUSES BY NAME. Either an idiom that reads those keys has moved out from under \`env_read VAR KEY\`, a literal-key \`env_get KEY\` and $ENV_EXAMPLE, or the floor's own surface has. This harness compares only the keys it derives, so an uncovered key is a refusal nothing here tests."
  say "   keys compared: ${#KEYS[@]}, derived from env_read sites + literal-key env_get sites + $(basename "$ENV_EXAMPLE")"
  say "   floor met — every key A5 refuses by name is covered: $(derive_floor_keys "$DEPLOY" | tr '\n' ' ')"

  # Seen to fail: a refactor that routes the `env_read` call sites through a new helper. The refused keys
  # that `.env.example` also names survive it through that leg of the union; MYSQL_ATTR_SSL_CA — the key
  # the TLS refusal turns on — is named by no `.env.example`, so the floor is what notices.
  mutant_deploy="$(mutant key-idiom-moved 's/^\( *\)env_read \([a-z_]* [A-Z]\)/\1env_read_checked \2/')" || exit 2
  if [ -n "$(uncovered_floor_keys "$mutant_deploy")" ]; then
    say "   seen to fail  key-idiom-moved   every env_read site renamed → the floor reds"
  else
    fail "the key floor did NOT red for a deploy.sh whose every \`env_read VAR KEY\` site had been renamed, so it cannot report a derivation that has narrowed — which is what it exists for."
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
  # shellcheck disable=SC2016  # $repository and $leak are PHP source, and sed has to receive them as
  # text. Measured: the expanding spelling reaches sed as `s|^     = freshRepository();$|…` and matches
  # nothing, which the cmp below would then report as the oracle's repository having moved.
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
  say "   population: $POP_SCAN cells — ${#BASES[@]} bases × the terminator axes ${TERM_NAMES[*]}, derived from Parser::parse and from env_lines_load"
  say "   keys compared, derived from the three idioms that read a key from .env: ${KEYS[*]}"
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
  # shellcheck disable=SC2016  # `${content//…}` is deploy.sh's OWN source text — the two lines this
  # address deletes are its `content="${content//$'\r\n'/$'\n'}"` pair — so it travels as literal text.
  control scan lf-only 'env_lines_load splits on \n alone (card#9561 r4)' \
    '/^  content="\${content\/\//d'
  control scan no-scan 'env_file_scan certifies every file' \
    's|^env_file_scan() {$|env_file_scan() { return 0|'
  # shellcheck disable=SC2016  # $ENV_LINES_NUL is the variable NAME inside the deploy.sh line being
  # matched. Measured: expanded, the pattern reaches sed as `\[ "" = 1 \]` and matches nothing.
  control scan nul-blind 'the NUL refusal is cut out' \
    's|^  if \[ "\$ENV_LINES_NUL" = 1 \]; then$|  if false; then|'
}

do_locality() {
  # Double-quoted, not single: the apostrophe is U+2019 in prose, and double quotes are how ShellCheck
  # is told a unicode quote is literal (SC1112). The string holds nothing that expands.
  head2 "LOCALITY — A5’s store verdict  vs  where Laravel really connects"
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
  # shellcheck disable=SC2016  # the same address over the same two deploy.sh lines as in do_scan.
  control locality lf-only 'env_lines_load splits on \n alone (card#9561 r4)' \
    '/^  content="\${content\/\//d'
  control locality ca-text 'the CA is judged by its TEXT, not by the value the app receives' \
    's|^env_app_falsy() {$|env_app_falsy() { return 1;|'
  control locality url-blind 'store_locality stops reading DB_URL' \
    's|^  env_read url DB_URL .*$|  url=""|'
  # shellcheck disable=SC2016  # ${socket:0:1} is deploy.sh's own text on the pattern side and $socket is
  # bash source on the replacement side. Measured: expanded, the pattern matches nothing.
  control locality socket-text 'any DB_SOCKET text counts as a socket' \
    's|^  if \[ "\${socket:0:1}" = "/" \]; then$|  if [ -n "$socket" ]; then|'
}

# ── run ───────────────────────────────────────────────────────────────────────────────────────────────
printf '\nenv-mirror-diff.sh — %s\n' "$DEPLOY"
printf 'oracle: %s (phpdotenv %s)\n' "$REPO/server/vendor" \
  "$(sed -n 's/.*"version": "\(v[0-9.]*\)".*/\1/p' <<< "$(grep -A2 '"name": "vlucas/phpdotenv"' "$REPO/server/composer.lock")" | head -1)"
head2 'the loopback set — two statements, held together'
check_loopback_sets
head2 'the line terminators — two statements, held together'
check_terminator_sets
head2 'the keys compared — three idioms, held against a floor'
check_key_derivation
# Double-quoted for the same reason as do_locality's heading above: a literal U+2019, nothing that expands.
head2 "the mirror’s fixture root — which .env files it may read and print"
check_mirror_confinement

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

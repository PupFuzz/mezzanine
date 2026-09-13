#!/usr/bin/env bash
# supervision.sh — what cron supervises on a Mezzanine host, stated ONCE, and the one way to install it.
#
# WHY IT EXISTS. Operator ruling 2026-09-13: "the web app should not need root access" — and it binds
# prod, which "is set up the same way as sandbox". With no root there are no systemd units: THIS
# USER'S crontab supervises the long-lived daemons of `docs/design/FLEET-STATE.md § 2.1`. Every minute
# cron starts each one under `flock -n`: while the running copy holds its lock that is a no-op, and a
# daemon that died is started again within 60 s. `@reboot` starts them at boot.
#
# ONE SOURCE. SUPERVISED_DAEMONS below is the only list of supervised commands a program reads.
# `bin/deploy.sh` sources this file: it refuses a host whose installed crontab lacks any entry
# `render` prints (A13), and it restarts each daemon with exactly the command cron runs.
# `bin/deploy.selftest.sh` checks the list against § 2.1's `long-lived daemon` rows, so the two cannot
# drift apart silently — the drift card#9181 found, when § 2.1 lacked the heartbeat this set carried.
# `mezzanine:purge` is NOT in the set: it is a scheduled command, run by the `schedule:run` entry.
#
# USAGE
#   bin/supervision.sh render  [--root <checkout>] [--php <binary>]   print the managed crontab block
#   bin/supervision.sh install [--root <checkout>] [--php <binary>]   install it into this user's crontab
#   bin/supervision.sh daemons                                        print the supervised commands
#
#   --root  the checkout — the directory holding server/   [default: the repo this script lives in]
#   --php   the PHP CLI the daemons run under              [default: `php` on PATH, symlinks resolved]
#
# INSTALL is the documented way (docs/PLAN.md § 5), run once as the application user when a host is
# stood up, and again after a release changes what this file renders — A13 refuses the deploy until
# then, naming the missing lines. It replaces only the block between THIS checkout's BEGIN/END
# markers and keeps every other line of the crontab. It REFUSES, changing nothing, when:
#   · `crontab -l` fails for any reason other than "no crontab for <user>" — writing over a crontab
#     this script could not read would destroy it;
#   · a line OUTSIDE every managed block already runs a supervised command — a hand-staged crontab is
#     that case, and installing beside it would run every daemon twice, under two different locks.
#     Remove those lines (`crontab -e`) and install again.
#     ⚠ THEN STOP THE DAEMONS THOSE LINES STARTED. They hold the OLD lock files, so cron's new entries
#     start a second copy of each beside them, and no deploy will ever restart them: bin/deploy.sh
#     restarts whatever holds THIS script's locks and nothing else. Stop them by their old lock files,
#     which reaches nothing else: `fuser -k -TERM <old lock file>…`.
#
# LOCKS are per checkout — server/storage/framework/daemon-<name>.lock, git-ignored there — so two
# checkouts under one account never share one, and a deploy's proof that a lock is held is a proof
# about THIS checkout's daemon. Output goes to server/storage/logs/daemon-<name>.log.

# The supervised set. Add a long-lived daemon to § 2.1, add it here; the selftest reds until both agree.
SUPERVISED_DAEMONS=(mezzanine:fold mezzanine:sweep mezzanine:feed-heartbeat)

# A path cron can carry verbatim. cron ends a command at an unescaped `%`, and a space would split the
# words the shell runs; quoting them would make a line nobody can match whole, so they are refused.
supervision_path_ok() { case "$1" in '' | *[!A-Za-z0-9/._+-]*) return 1 ;; esac; }

supervision_default_php() {
  local p
  p="$(command -v php 2>/dev/null)" || return 1
  readlink -f "$p"
}

supervision_daemon_name() { printf '%s' "${1#mezzanine:}"; }

supervision_lock() { # <root> <artisan-command>
  printf '%s/server/storage/framework/daemon-%s.lock' "$1" "$(supervision_daemon_name "$2")"
}

# The command cron runs for one daemon — and the one bin/deploy.sh runs to restart it.
supervision_command() { # <root> <php> <artisan-command>
  printf 'cd %s/server && flock -n %s %s artisan %s >> storage/logs/daemon-%s.log 2>&1' \
    "$1" "$(supervision_lock "$1" "$3")" "$2" "$3" "$(supervision_daemon_name "$3")"
}

# The crontab entries, and nothing else. Fails (prints nothing) on a path cron cannot carry.
supervision_entries() { # <root> <php>
  supervision_path_ok "$1" && supervision_path_ok "$2" || return 1
  local c
  printf '* * * * * cd %s/server && %s artisan schedule:run >> /dev/null 2>&1\n' "$1" "$2"
  for c in "${SUPERVISED_DAEMONS[@]}"; do printf '* * * * * %s\n' "$(supervision_command "$1" "$2" "$c")"; done
  for c in "${SUPERVISED_DAEMONS[@]}"; do printf '@reboot %s\n' "$(supervision_command "$1" "$2" "$c")"; done
}

supervision_begin() { printf '# BEGIN mezzanine-supervision %s' "$1"; }
supervision_end() { printf '# END mezzanine-supervision %s' "$1"; }

supervision_render() { # <root> <php>
  local entries
  entries="$(supervision_entries "$1" "$2")" || return 1
  supervision_begin "$1"; printf '\n'
  cat <<'HEADER'
# Managed by `bin/supervision.sh install` — change that script, not these lines (docs/PLAN.md § 5).
# cron supervises the long-lived daemons of docs/design/FLEET-STATE.md § 2.1: `flock -n` makes each
# minute's start a no-op while the running copy holds its lock. bin/deploy.sh restarts them.
HEADER
  printf '%s\n' "$entries"
  supervision_end "$1"; printf '\n'
}

supervision_die() {
  printf 'supervision.sh: %s\n' "$1" >&2
  shift
  local l
  for l in "$@"; do printf '%s\n' "$l" | sed 's/^/  /' >&2; done
  exit 1
}

supervision_install() { # <root> <php>
  local root="$1" php="$2" block current err rc=0 stripped outside dupes c line
  block="$(supervision_render "$root" "$php")" \
    || supervision_die "cron cannot carry '$root' / '$php' verbatim" \
      "Both must be plain paths of [A-Za-z0-9/._+-]: cron ends a command at '%'."

  err="$(mktemp)"
  current="$(crontab -l 2>"$err")" || rc=$?
  if [ "$rc" -ne 0 ]; then
    if grep -q '^no crontab for ' "$err"; then
      current=""
    else
      supervision_die "\`crontab -l\` failed (exit $rc), and not because the crontab is empty:" \
        "$(cat "$err")" "Nothing was written: replacing a crontab this script could not read would destroy it."
    fi
  fi
  rm -f "$err"

  if grep -Fxq -- "$(supervision_begin "$root")" <<< "$current" \
    && ! grep -Fxq -- "$(supervision_end "$root")" <<< "$current"; then
    supervision_die "the crontab has this checkout's BEGIN marker and no END marker" \
      "Nothing was written. Repair it with \`crontab -e\`, then install again."
  fi
  stripped="$(awk -v b="$(supervision_begin "$root")" -v e="$(supervision_end "$root")" \
    '$0 == b { skip = 1; next } $0 == e { skip = 0; next } !skip' <<< "$current")"

  outside="$(awk '/^# BEGIN mezzanine-supervision /{ skip = 1; next } /^# END mezzanine-supervision /{ skip = 0; next } !skip && !/^[[:space:]]*#/' <<< "$stripped")"
  dupes=""
  for c in schedule:run "${SUPERVISED_DAEMONS[@]}"; do
    while IFS= read -r line; do
      [ -z "$line" ] || dupes+="$line"$'\n'
    done < <(grep -E -- "artisan[[:space:]]+${c}([[:space:]]|\$)" <<< "$outside" || true)
  done
  [ -z "$dupes" ] || supervision_die "the crontab already runs a supervised command outside the managed block:" \
    "$(printf '%s' "$dupes" | sed 's/^/| /')" \
    "Nothing was written. Installing beside these would run each daemon twice, under two locks." \
    "Remove them with \`crontab -e\`, then install again."

  { if [ -n "$stripped" ]; then printf '%s\n' "$stripped"; fi; printf '%s\n' "$block"; } | crontab -

  # Read back, not assumed: every entry must now be in the installed crontab, whole-line.
  current="$(crontab -l 2>/dev/null)" || supervision_die "\`crontab -l\` failed right after installing"
  while IFS= read -r line; do
    grep -Fxq -- "$line" <<< "$current" || supervision_die "installed, but the read-back lacks: $line"
  done < <(supervision_entries "$root" "$php")
  printf 'installed into the crontab of %s:\n%s\n' "$(id -un)" "$block"
}

supervision_main() {
  local cmd="${1:-}" root php
  [ $# -eq 0 ] || shift
  root="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.." && pwd)"
  php="$(supervision_default_php || true)"
  while [ $# -gt 0 ]; do
    case "$1" in
      --root) root="${2:?--root needs a value}"; shift 2 ;;
      --php) php="${2:?--php needs a value}"; shift 2 ;;
      *) supervision_die "unknown argument: $1" ;;
    esac
  done
  case "$cmd" in
    daemons) printf '%s\n' "${SUPERVISED_DAEMONS[@]}" ;;
    render | install)
      [ -f "$root/server/artisan" ] || supervision_die "$root is not a Mezzanine checkout (no server/artisan)"
      [ -n "$php" ] && [ -x "$php" ] || supervision_die "no executable PHP ('${php:-none on PATH}'); pass --php <binary>"
      if [ "$cmd" = render ]; then
        supervision_render "$root" "$php" || supervision_die "cron cannot carry '$root' / '$php' verbatim" \
          "Both must be plain paths of [A-Za-z0-9/._+-]: cron ends a command at '%'."
      else
        supervision_install "$root" "$php"
      fi
      ;;
    *) sed -n '/^# USAGE/,/^# INSTALL/p' "${BASH_SOURCE[0]}" | grep -v '^# INSTALL' | sed 's/^# \{0,1\}//'; [ "$cmd" = -h ] || [ "$cmd" = --help ] ;;
  esac
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  set -Eeuo pipefail
  supervision_main "$@"
fi

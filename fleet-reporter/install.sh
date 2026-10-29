#!/usr/bin/env bash
# fleet-reporter/install.sh — a SHIM FOR ONE RELEASE (card#12571, D6 § 3.6, the operator's ruling Q5).
#
# The installer is `install.js`, beside this file: one node implementation for Linux, Windows and macOS
# (`INSTALL.md` is its reference). This script runs it with the same arguments, so a seat or a note that still
# names `install.sh` keeps working through the reporter release that ships `install.js` (0.6.0), and it is
# removed in the reporter release after that (`UPDATES.md`'s 0.6.0 row names both numbers).
#
# One argument is refused: `--set-token`. Its meaning changed — the token now comes from the file the Seat
# tokens page saves, given by path with `--token-file`, never from a prompt — so it is refused by name rather
# than passed on to mean something else.
set -euo pipefail

for arg in "$@"; do
  if [ "$arg" = --set-token ]; then
    printf 'install.sh: --set-token is gone: save the token file from the Seat tokens page and run\n' >&2
    printf '  node %s/install.js --token-file <where you saved or copied it>\n' "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)" >&2
    printf 'NOTHING RUN\n' >&2
    exit 1
  fi
done
exec node "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)/install.js" "$@"

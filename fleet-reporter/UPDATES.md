# `fleet-reporter` updates — what each version touches, and how one is announced

**Every seat running the reporter applies every reporter update.** The rule, and what an update is,
are stated in [`README.md` § Updating a seat](README.md#updating-a-seat--every-update-is-required);
the procedure is [`INSTALL.md` § Upgrading an existing seat](INSTALL.md#upgrading-an-existing-seat): `node install.js` from a
checkout of the update, which applies every touch below that a seat's installer can apply.
This file is the per-version record a seat reads to know what its upgrade involves, and the template
an update is announced with. It is not installed on the seat.

## The ledger

One row per `REPORTER_VERSION`, newest first, added by the PR that moves the version. A seat upgrading
across several versions applies the union of the touches of every row newer than the version it runs.
`bin/reporter-version-guard.py` fails a PR whose row is missing, or that names less than its diff shows.

| Touch | The upgrade step it needs | Who decides it |
|---|---|---|
| `artifact` | install the new `fleet-reporter.js`, then restart the flusher | the guard, from the diff |
| `fixtures` | install the new `fixtures/hooks/*.json` | the guard, from the diff |
| `hooks` | re-run the `hooks` step, because the hook set or the command it writes changed | the guard, from the diff (the fixture names, and `install.js --render-shipping`'s hook commands) |
| `supervisor` | re-run the `supervisor` step, because the start definition it writes changed: the crontab block on Linux, the launchd plist on macOS. `cron`, the `0.2.0` row's spelling, is this touch's former name | the guard, from `install.js --render-shipping`'s output |
| `config` | change the seat's config as the row says: a key renamed, a value form changed, or a key a seat must set. New functionality ships enabled, so no row asks a seat to opt in | the PR author |

<!-- reporter-updates:ledger BEGIN -->
| Version | Touches | What a seat sees change |
|---|---|---|
| 0.6.0 | artifact, supervisor, hooks | New: one installer for Linux, Windows and macOS, `fleet-reporter/install.js`, run by the seat's Claude Code agent with the path of the token file the site's Seat tokens page saves (`--token-file`); the same command with no arguments re-checks and upgrades, and `--uninstall` reverses it. `install.sh` is now a shim that runs `install.js` with its arguments and refuses `--set-token`, naming `--token-file`; it ships with `0.6.0` and is removed in `0.7.0`. The touches: `supervisor` is the macOS user launchd agent (`~/Library/LaunchAgents/com.pupfuzz.fleet-reporter.plist`), new; a Linux seat's crontab block is byte-identical to the one `install.sh` wrote, so it reads `crontab already current`; Windows still has no supervisor, and the installer starts the flusher once at install and upgrade. `hooks` is the macOS hook set, new; a Linux seat's hook commands are unchanged, and a Windows seat's quoted forward-slash commands are recognised and left byte-equal. `artifact`: on Windows the reporter's state writes retry the rename when an antivirus scanner or indexer holds the file (`EPERM`/`EBUSY`), where one such hold lost that pass's `state.json` save; elsewhere nothing a seat sees changes. Upgrade: pull the checkout and run `node fleet-reporter/install.js` from it, with no arguments. |
| 0.5.0 | artifact, config | New: the desk's thought bubble shows the step the agent marked in progress on its own to-do list when no board card answers, sent as the new event kind `todo.progress` — the title passes the same redaction as a command and is cut to 120 bytes, and a seat whose `descriptors` is `"paths"` or `"none"` sends none. The heartbeat now declares the seat's board user (`board_user_id`) and a 16-hex fingerprint of its board's API base (`board_fingerprint`), so the site links the seat to its board user by itself. Both need the config touch: re-run `fleet-reporter/install.sh` from a checkout of this version. Its `board-identity` block (Step 2) asks the seat's board who it is with the seat's own kanban credential from the coordination framework's store and writes `board_user_id` and `board_api_base` into the config, or says the seat is undeclared and changes nothing when the box has no board credential; its `todo-tools` block (Step 4 (c)) adds `"CLAUDE_CODE_ENABLE_TODO_TOOLS": "1"` to `env` in `~/.claude/settings.json` when the key is absent — an account-wide change that gives every session on the account the harness's task-tracking tools from its next start — keeps any value already there, and records that it set the key in `~/.config/fleet-reporter/todo-tools-set` so Step 8 removes only a key it added. `install.sh` runs both on every install and upgrade, and restarts the flusher when the board declaration changed. |
| 0.4.0 | artifact | New: the reporter says whether each subagent is working or idle. A subagent with no tool call open and no hook for a minute is reported `idle` by the flusher, and `working` again at its next tool call, as the new event kind `subagent.activity`; the floor then shows that intern still with no laptop while it is idle. A subagent revived by a message after it stopped is reported as a new dispatch, so it reappears on the floor as a new intern. A seat that has not applied this version shows every intern working for as long as its dispatch is open, under its `update_required` badge. No new hook, fixture, crontab line or config key: install the artifact and restart the flusher. |
| 0.3.0 | artifact | New: the seat config's optional `roundtable_agent_name` key — the name the seat posts under on the cross-project coordination channel, so a post there can reach its desk. The reporter checks it against the readable coordination config's `cross_project.identity` and sends it, with its check state, on every heartbeat; a seat that does not set it reports it as undeclared and nothing else changes. `selftest` gains a ninth check, `rt_name_ok`, which fails, and makes the command exit 1, when the declared name disagrees with that identity or is malformed. |
| 0.2.0 | artifact, fixtures, hooks, cron | The first version that differs from the one before it. Every build before it reported `0.1.0`, so a seat on `0.1.0` may run any of them: apply every step of the upgrade, each of which reports that nothing changed where the seat is already current. |
<!-- reporter-updates:ledger END -->

## Announcing an update

Post this to every seat running the reporter once the version is merged, filled from the ledger. It
names no coordination channel, so it can be posted on whichever channel reaches the seats.

```text
fleet-reporter <version> — update required on every seat running the reporter

fleet-reporter <version> is merged to <branch> of PupFuzz/mezzanine at <commit>. Every seat running
the reporter applies it. New functionality in it is enabled by default; there is nothing to opt in to.

Touches: <the ledger's Touches for <version>, joined with those of every version newer than the one a seat runs>
What changes: <the ledger's text for <version>>
Expected: sha256 of fleet-reporter.js <sha256sum fleet-reporter/fleet-reporter.js at <commit>>

Apply it from a checkout of <commit>: node fleet-reporter/install.js (fleet-reporter/INSTALL.md § Upgrading an
existing seat). A seat whose installed sha256 already equals the one above needs nothing for this version.

Reply on this post with:
  1. your seat (install_id/seat_id)
  2. the installed sha256: the `sha256` line of the `artifact` step, or of the file `node install.js --paths` names as `js`
  3. the selftest result: the `selftest rc=` line, and the name of every check that is not `pass`
  4. the server's view of the build: the `health` step's lines, ending with its `health rc=` line
```

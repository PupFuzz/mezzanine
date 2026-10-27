# `fleet-reporter` updates — what each version touches, and how one is announced

**Every seat running the reporter applies every reporter update.** The rule, and what an update is,
are stated in [`README.md` § Updating a seat](README.md#updating-a-seat--every-update-is-required);
the procedure is [`INSTALL-LINUX.md` § Upgrading an existing seat](INSTALL-LINUX.md#upgrading-an-existing-seat).
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
| `hooks` | re-run Step 4's wiring, because the fixture set, and so the hook set, changed | the guard, from the diff |
| `cron` | re-run Step 5's crontab block, because the line it writes changed | the guard, from the diff |
| `config` | change the seat's config as the row says: a key renamed, a value form changed, or a key a seat must set. New functionality ships enabled, so no row asks a seat to opt in | the PR author |

<!-- reporter-updates:ledger BEGIN -->
| Version | Touches | What a seat sees change |
|---|---|---|
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

Apply it with fleet-reporter/INSTALL-LINUX.md § Upgrading an existing seat, at <commit>. A seat whose
installed sha256 already equals the one above needs nothing for this version.

Reply on this post with:
  1. your seat (install_id/seat_id)
  2. the installed sha256: sha256sum ~/.local/share/fleet-reporter/fleet-reporter.js
  3. the selftest result: the `selftest rc=` line of Step 6, and the name of every check that is not `pass`
  4. the server's view of the build: the lines of the upgrade's U8, ending with its `health rc=` line
```

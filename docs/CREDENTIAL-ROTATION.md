# Rotating the database password, when one login serves every consumer

**Read this before you change the MariaDB password on a host that runs both this application and
the agent webhook bridge.** On such a host the two authenticate as the **same** login — they hold
separate schemas, not separate accounts — and nothing in either checkout tells the other that the
credential is shared. A rotation that updates one `.env` and not the other leaves the second
pointed at a value the server no longer accepts, and the break is **silent in both directions**:
GitHub reports no delivery failure, and the consumer that broke has no route to anybody watching.

That is not hypothetical. On 2026-09-14 the password was rotated, `server/.env` was updated, the
bridge's was not, and the bridge returned HTTP 500 to **every** webhook delivery for 38 hours.
Cards stopped advancing on PR events; a `pull_request/closed` carrying a merge was dropped. The
cards that went missing in that window were then skipped, silently, by the next release promote —
a queue that drops events does not resume, it resumes minus what it dropped. `card#9660` carries
the measurement.

⛔ **Separating the identities is not the fix, and is not available.** On the sandbox host there is
exactly one MariaDB login and no route to create a second: there is no root here, the account the
application uses lacks `CREATE USER`, and the hosting panel refuses to a non-root caller (measured
2026-09-20, `card#9660`). **Operator ruling, permanent: a per-consumer database user is off the
table.** So the coupling cannot be removed. What it can be made is **loud and complete** — which is
what this document is for.

⛔ **No password value belongs in any output stream.** Not in `argv` (the process table and your
shell history read it), not in a log, not in a paste to anyone. Type it at the `mariadb` prompt
rather than passing it with `-e`; set it into a file with an editor rather than a `sed` command
line. `SHOW GRANTS` and `SELECT … FROM mysql.user` print a password **hash**, which is a secret
value — neither appears anywhere below, and neither is needed. **No command in this document
resolves a password**, so every one of them is safe to run; the two that can print the database
user and host say so where they are used.

---

## The consumer set is derived, not remembered

The table below is the **declaration** — a far end cannot check a contract it cannot read, so the
consumers are named here in one place that the rotator reads. But a written list ages, and this one
will: agent worktrees come and go, and a new consumer arrives with no obligation to edit this file.
**So the list travels with the command that re-prints it. Run the derivation; do not trust the
table.**

⛔ **Derive by CONTENT, never by filename.** A sweep for `.env*` is the one anybody writes and it is
not enough: on 2026-09-20 a content sweep found a file named `env.bak`, under `~/.cache`, holding a
non-empty password — invisible to every name-based pattern that would occur to you. What makes a
file a consumer is the line it holds, so match on the line.

⛔ **And walk the tree with `find`, never with `grep -r`.** This is not a style preference. A
recursive `grep` may honour `.gitignore`, and **`.env` is gitignored in every one of these
checkouts** — so the sweep that matters most silently returns *nothing*, and an empty result reads
as "no consumers". Measured on this host, 2026-09-20: `grep -rl` from an agent session answered
with none of the `.env` files, because the session's `grep` is `ugrep` run with `--ignore-files`;
`/usr/bin/grep` is GNU grep 3.12 and does not do that. **The command below cannot be affected
either way**, because `find` hands `grep` explicit file operands and no recursion happens inside
it. It takes about ten seconds over this account.

```sh
# Every file under this account that holds a non-empty database password, whatever it is NAMED.
# Prints PATHS ONLY.
find ~ -type f -not -path '*/.git/*' -not -path '*/node_modules/*' -not -path '*/vendor/*' -print0 |
  xargs -0 grep -lIE '^DB_PASSWORD=.+' 2>/dev/null
```

That population includes files that are not credentials: each checkout's `bin/deploy.selftest.sh`
carries `.env` fixture payloads, committed and public. The second command tells them apart by
asking which files hold **the value the application is using right now** — and it prints `LIVE` or
`OTHER` and never a value, because the pattern travels through a file descriptor rather than
`argv`, and the match itself is discarded by `-q`:

```sh
APP=~/mezzanine/server/.env
if ! grep -qE '^DB_PASSWORD=.+' "$APP"; then
  echo "REFUSED: $APP holds no non-empty DB_PASSWORD, so there is nothing to compare against."
else
  find ~ -type f -not -path '*/.git/*' -not -path '*/node_modules/*' -not -path '*/vendor/*' -print0 |
    xargs -0 grep -lIE '^DB_PASSWORD=.+' 2>/dev/null |
    while IFS= read -r f; do
      if grep -qFf <(grep -h '^DB_PASSWORD=' "$APP") "$f"; then
        printf 'LIVE   %s\n' "$f"
      else
        printf 'OTHER  %s\n' "$f"
      fi
    done
fi
```

⛔ **The refusal on the first line is load-bearing, and it is not defensive padding.** Measured
2026-09-20, both ways: `grep -qFf` against an **empty** pattern file matches nothing under GNU grep
3.12 and matches **every line** under the `ugrep` an agent session runs. So on one of the two, a
reference file that is missing, unreadable, or carrying an empty `DB_PASSWORD` makes every path in
the sweep answer `LIVE` — a check that measured nothing, reporting that everything is in sync. An
absent answer reading as health is the exact defect this document exists to close, and it was one
line away from being built into the document's own instrument.

**`LIVE` is the rotation checklist**: every one of those files must be updated in the same act, or
deleted. **`OTHER` is two different things** and the path tells you which — a committed fixture or
a document (leave it), or a **retired credential still readable on disk** (delete it; see below).
Seen to discriminate on 2026-09-20: the application's, the bridge's and one worktree's `.env`
answered `LIVE`, every other path in the population answered `OTHER`, and a reference file with no
password fired the refusal instead of reporting a clean sweep.

A second sweep catches a credential held in some shape other than a `KEY=value` line — a PHP-FPM
pool injecting `env[DB_PASSWORD]`, a `mysqli.default_pw` in a `php.ini`, a `~/.my.cnf`. It printed
nothing on the sandbox host on 2026-09-20, and it is not scheduled, so run it:

```sh
find ~/etc ~/domains -type f -print0 |
  xargs -0 grep -lE 'env\[DB_|env\[MYSQL|^[[:space:]]*mysqli\.default_pw[[:space:]]*=[[:space:]]*\S' 2>/dev/null
ls -la ~/.my.cnf ~/.mylogin.cnf 2>/dev/null
```

## Who holds the credential, and who merely rides it

Two kinds, and the difference decides what you do about each.

**DIRECT** — holds a copy. A rotation must update it or it breaks.

| Path | What breaks if you miss it | How it was established (2026-09-20) |
|---|---|---|
| `~/mezzanine/server/.env` | the web application, its cron-supervised daemons, `artisan` in that checkout, and the test suite | the sweep answers `LIVE` — this is the file it compares the others against |
| `~/agent-webhook-bridge/.env` | GitHub webhooks 500 → the board mover never advances a card on a PR event, and mid-session live-wake dies with it. This is the 38-hour outage | the sweep answers `LIVE`; and its `DB_USERNAME` line is **byte-identical** to the application's too, by `cmp -s` on that one line, with no value printed. `DB_DATABASE` differs — each has its own schema on the one login |
| any agent worktree's `server/.env` | that tree's `artisan` and test runs fail access-denied, which reads as a broken branch rather than a stale file | the sweep answers `LIVE` for a tree under `~/wt`, whose `DB_USERNAME` and `DB_DATABASE` lines are the application's as well |

Worktree copies are **transient by design**, so the right treatment is *delete or re-copy*, never
*maintain*. They matter here only because a stale one costs an agent a confusing hour.

**INDIRECT** — connects using someone else's stored copy. A rotation does not update these, but it
does change what they report, and some of them need an action anyway.

| Consumer | Whose copy it rides | What the rotation does to it |
|---|---|---|
| the long-lived daemons cron supervises — `bin/supervision.sh daemons` prints the set | `server/.env`, read **once, at start** | nothing, until the connection next drops. Each is a PHP process that has been running since an earlier minute's cron; it will not re-read the file. **Restart them** — see below |
| `php artisan schedule:run`, every minute | `server/.env` | nothing to do: a fresh process each minute reads the new file |
| PHP-FPM, both vhosts | `server/.env` and the bridge's | nothing to do **unless a config cache exists** — see below |
| the test suite, in any checkout | that checkout's `server/.env` | fails access-denied if that copy is stale. `server/tests/bootstrap.php` says in its own comments why no bootstrap-time check can see a *wrong* credential, only a missing or unreadable file |
| `bin/deploy.sh` | `server/.env` | reads it in phase A and runs migrations in the window; a stale copy fails the deploy |
| `~/.local/bin/bridge-db-watch.sh` (`card#9660` leg 1, cron `*/15`) | the **bridge's** `.env` — it runs the bridge's own `artisan` | its verdict flips to `FAILING`. This is the instrument you verify the bridge leg with |

**Checked and NOT a consumer**, recorded so the next reader does not sweep them again (2026-09-20):
the CI lane (`.github/workflows/php-tests.yml` runs a MariaDB service container and exports
`DB_USERNAME: root` with an empty password at the job level — it never sees this host's login);
`tools/ci-store-probe.php` (reads what that lane exports); `bin/env-mirror-diff.sh` (its own header:
reaches no host, needs no credential); the PHP configuration under `~/etc` and `~/domains` (no
`env[DB_*]`, no password directive set); the fleet-reporter cron job (its only `mysql` occurrence is
a redaction selftest fixture); the coordination tooling under `~/.local/bin` (no MariaDB client);
and the scratch checkouts under `~/.cache/coord`, whose `.env` files carry an empty `DB_PASSWORD`.
⚠ Not everything under `~/.cache` is clear: an `env.bak` there holds a **retired** password — see
the section below.

### ⚠ A stale copy of a retired password is a trap and a surface

The sweep on 2026-09-20 found sibling files beside `~/agent-webhook-bridge/.env` — timestamped
`.bak` copies and an editor's `.env~` — each holding a **non-empty** `DB_PASSWORD` the sweep answers
`OTHER` for, and an `env.bak` under `~/.cache` that no `.env*` pattern would have reached at all.
They hold a superseded value: a retired secret, still readable on disk, one `cp` away from
reinstating the outage the way an operator restores a config they believe is good.

**Rotating does not clean these up, and they are not consumers.** Treat them as their own item: a
retired credential is deleted, not archived. The step below that takes a temporary backup is
deliberately paired with a step that deletes it, and this is why.

---

## The order is load-bearing: the refusable part goes first

A rotation is two mutations, and they can be done in either order:

* **S — the server side.** Change the password of the account `DB_USERNAME` names, at the
  `mariadb` prompt as an administrative user.
* **C — the config side.** Write the new value into every DIRECT consumer above.

**Work out which one can be refused on this host, because that decides the order.** S needs an
administrative MariaDB credential, and this account does not have one: measured 2026-09-20,
`mysql -e` as this unix user answers `Access denied … (using password: NO)`, `/usr/sbin/virtualmin`
answers `must be run as root`, and the application's own account lacks `CREATE USER`. So S happens
as root or through the hosting panel — a gate with a party on the other side of it who can say no,
or a value policy that can reject what you chose. C is a write to files this account owns; for the
operator at a terminal there is no gate on it at all.

⇒ **S is the refusable part. S goes first.**

Work through the other order and the reason is concrete rather than procedural. **C then S**: every
consumer now names a password the server has never heard of, so everything is down — and then S is
refused. To get back you must restore the *old* value into every consumer, and you have just
overwritten the only copies of it. The server cannot tell you what it was; it holds a hash. That is
a half-applied state with **nothing to revert to**.

**S then C**: the server accepts only the new value, so everything is down for the same window —
but you hold that value, and every remaining step is a local file write on files you own, with no
gate left that can refuse. And if C turns out to be impossible, S can be re-run, *because step 1
just proved you have the access to re-run it*. Doing the refusable part first is the only order
that establishes you can get back **before** anything irreplaceable is overwritten.

⚠ **Both orders have an outage window; the order is not what shortens it.** Shorten it by having C
staged before you touch S — every path from the derivation in hand, every file confirmed writable,
the editor open. Then the gap is seconds rather than however long it takes you to go looking.

⚠ **An agent seat does not run this procedure.** The `.env` write is gated there as a secret-store
write, and that denial is correct — it is what stopped `card#9660`'s instance fix until an operator
took it. An agent prepares the enumeration and hands it over; the operator rotates.

---

## The procedure

**0 — Stage everything C needs.** Run the derivation and the second sweep at the top of this file.
Every path answering `LIVE` is a file you must update. Confirm each is writable by you (`ls -l` on
the paths), and take a temporary backup of each, mode 600, in one directory **outside both
checkouts** — a backup left beside the original is what the next sweep finds and what the next
operator restores. That backup is the copy of the old value a rollback needs. **Step 6 deletes
them**, and skipping step 6 is how the `.env~` and `.bak` files above came to exist.

**1 — S, the refusable part.** As an administrative user, at the prompt, never with `-e`:

```sql
-- at `sudo mariadb`, so the value never lands in argv or shell history
ALTER USER '<the account DB_USERNAME names>'@'<its host>' IDENTIFIED BY '<the new password>';
```

Connections that are already open are **not** dropped by this — authentication happened when they
were made. That is why step 3 restarts the long-lived daemons rather than trusting the outage to
announce itself.

Everything that connects from here on is now broken. That is expected: it is the state you chose
deliberately, with the new value in your hand rather than out of it.

**2 — C, in one act, using step 0's list as the checklist.** Edit `DB_PASSWORD` in every DIRECT
consumer — the application's, the bridge's, and any worktree copy you are keeping (delete the ones
you are not). Use an editor. A `sed -i` whose pattern carries the value puts it in argv.

**3 — Make the edit take effect.** An edited file is not a running process.

| Consumer | Does the edit alone take effect? | Do this |
|---|---|---|
| PHP-FPM, either vhost | yes, on the next request — Laravel reads `.env` at boot — **unless a config cache exists**, in which case the file is inert until it is rebuilt. Check: `ls server/bootstrap/cache/config.php`, and the bridge's | run `php artisan config:clear` in **both** checkouts. It is a no-op where there is no cache and the whole fix where there is one |
| `schedule:run` | yes, next minute | nothing |
| the long-lived daemons | **no** | stop each by its lock file — `fuser -k -TERM <lock>` — and cron restarts it within 60 s. `bin/supervision.sh` names the locks and owns this recipe; its header's "moving a hand-staged crontab" walks the same stop-by-lock step |
| a worktree you kept | yes, next run | nothing |

⚠ **Why the daemons are the dangerous row.** Each holds a connection opened before the rotation.
The server does not close it, so the daemon keeps working and the break is *deferred* to whenever
that connection next drops and it reconnects with what it read at start — hours later, in its own
log, looking unrelated to anything you did. Restarting removes the question rather than answering
it.

**4 — Verify every consumer, not the one you were thinking about.** Next section.

**5 — Re-run the derivation.** Every consumer you just updated reads `LIVE` again. Anything still
reading `OTHER` is either a file you missed or a retired copy that wants deleting — read the path
and decide which.

**6 — Delete step 0's backups.** Verification is what gates this: once every consumer is confirmed
healthy, the old value has no remaining job, and a retired secret readable on disk is a surface.

---

## Verifying

| What | Command | What a pass proves — and what it does not |
|---|---|---|
| the application reaches the store | `cd ~/mezzanine/server && php artisan migrate:status` | rc 0 means this checkout's `.env` is accepted by the server. **Seen to fail** (2026-09-20): rc 0 healthy, and rc 1 carrying `SQLSTATE[HY000] [1045]` when the same command is run with a deliberately wrong password — so a pass here is evidence rather than decoration. ⚠ the failure text carries the database user and host: read it, do not paste it |
| the application is serving | `curl -sS -o /dev/null -w '%{http_code}\n' "$APP_URL/up"` | 200 means the app answers. **It says nothing about the store** — `/up` needs no credential (`bin/deploy.sh`'s own smoke step says so). Reading a green `/up` as a healthy database is exactly the false confidence this document exists to remove |
| the daemons came back | `tail ~/mezzanine/server/storage/logs/daemon-<name>.log` for each name `bin/supervision.sh daemons` prints | a fresh line after the restart, with no access-denied, means that daemon reconnected on the new value |
| the bridge reaches its store | `BRIDGE_DB_WATCH_STATE=/tmp/probe.state BRIDGE_DB_WATCH_LOG=/tmp/probe.log ~/.local/bin/bridge-db-watch.sh; echo rc=$?` | rc 0 is the bridge's own `DatabaseConnectivityCheck` reporting ok, immediately, without waiting for cron and without disturbing the live state file. rc 1 is `FAILING`, rc 2 is `UNMEASURED` — which is **not** a pass |
| the bridge works end to end | send a `ping` from the repository's webhook and read the delivery: `gh api repos/<owner>/<repo>/hooks --jq '.[].id'`, then `gh api -X POST repos/<owner>/<repo>/hooks/<id>/pings`, then `gh api "repos/<owner>/<repo>/hooks/<id>/deliveries?per_page=3" --jq '.[].status_code'` | 200 exercises the whole path — HMAC, routing, the store — rather than the connection alone. This is what proved the 2026-09-17 instance fix, and it is the strongest single check here |
| every stored copy agrees | the derivation at the top of this file | every consumer reads `LIVE`. It compares copies **to each other**, never to what the server accepts |

⚠ **An exported `DB_PASSWORD` in your shell beats the file.** Laravel's loader does not overwrite a
variable that is already in the environment, so a verification run in a shell where you exported
the value proves that the *value* works and nothing at all about the *file*. Verify in a clean
shell. (Measured 2026-09-20 — it is how the negative control above was produced.)

---

## What is checked automatically, and what is not

**Checked.** The bridge's ability to reach its database, by `~/.local/bin/bridge-db-watch.sh` every
15 minutes, writing a verdict to `~/.cache/coord/bridge-db-watch.state` with the history in the
`.log` beside it. It runs the bridge's own `DatabaseConnectivityCheck` and emits a **severity
only** — never the finding's message, which is a PDO error carrying the database user and host. A
check that is absent or unparseable is recorded as `UNMEASURED`, never as health.

**Not checked, and each of these is a real hole rather than a formality:**

* **The application's own store reachability is watched by nothing.** The watcher covers the bridge
  leg. If a rotation leaves `server/.env` stale, no scheduled thing notices.
* **The watcher's verdict reaches a human only when someone runs the reader.**
  `~/.local/bin/bridge-db-watch-report.sh` exists, is proven, and refuses to read a stale `ok` as
  health — but mounting it on session start needs an edit this account's agent seat is denied, and
  `grep -l 'bridge-db-watch' ~/.claude/settings.json` returned nothing on 2026-09-20. Until an
  operator mounts it, the verdict lands in a file. **Run the reader by hand after a rotation.**
* **Detection is 15-minutely and on-box.** Nothing pages anyone, and nothing leaves this host.
* **No worktree copy is watched by anything.**
* **A copy that is wrong in the same way everywhere is invisible to a comparison.** The derivation
  answers "do the copies agree", never "does the server accept them". Only a connection answers
  that, and only the bridge leg has one scheduled.
* **A consumer outside the derivation is seen by nothing.** The sweeps cover files holding a
  line-initial `DB_PASSWORD=` under this account, plus the PHP-configuration shapes named above. A
  credential held some third way — a systemd unit, another user's home, a remote host — is found by
  neither, and neither sweep is scheduled.
* **Whether this MariaDB accepts a second concurrently-valid password** — the feature that would
  remove the outage window entirely, by letting every consumer move across before the old value is
  retired — **is not established here. Nobody has checked.** If this server has it, the procedure
  should be rewritten around it: the order stays server-first (add the new value, migrate every
  consumer, then discard the old one), and what disappears is the window in which everything is
  down.
* **This enumeration was measured on the sandbox host.** Another host's consumer set is whatever
  its own derivation prints. Run it there; do not carry this table across.

### Recommended, not built: a consumer check that can run unattended

The derivation above is a command an operator remembers to run, which is the same shape as the
declaration nobody checked. **Turning it into a scheduled check is cheap and needs no secret
write and no database connection** — the whole comparison is a `grep -q` whose pattern arrives on a
file descriptor, so nothing is printed and nothing reaches `argv`. It would:

* re-derive the population every run rather than reading a committed list, so a new consumer is
  covered the day it appears — walking the tree with `find`, for the reason at the top of this
  file: a check that a `.gitignore` can silence is worse than no check, because it reports clean;
* print `LIVE` / `OTHER` per path and exit non-zero when a known consumer stops answering `LIVE`;
* report a `.bak` / `.env~` hit as a *stale copy* finding rather than a failure, so the class in
  the section above stops accumulating silently;
* carry a control seen to fail — a fixture pair that differs must red it — because a check that
  cannot fail is a decoration;
* sit beside the existing watcher in `~/.local/bin`, since its population spans two checkouts and
  cannot live inside either one.

What it would still not do is the limit named above: all copies agreeing is not all copies being
right. It is a cheap guard against the exact defect that cost 38 hours, and it is not a health
check. It would also carry the refusal above — a reference file it cannot read stops the run rather
than passing it.

## If a consumer is found broken afterwards

**Do not re-rotate.** A second rotation multiplies the stale copies instead of fixing one.

1. **Name which consumer.** Run the derivation. A consumer answering `OTHER` is a file that was
   missed; `LIVE` everywhere points at something else — a daemon that was never restarted,
   a config cache that was never cleared, or a shell that exported `DB_PASSWORD`.
2. **If it is a missed file**, copy the live value into it, `php artisan config:clear` in that
   checkout, and verify with the instrument for that consumer above. This is exactly what the
   2026-09-17 instance fix did for the bridge, and a webhook `ping` returning 200 is what proved it.
3. **If nothing accepts the new value**, the server side is what is wrong. Re-run step 1 with a
   value you hold, then redo step 2 onward. Step 0's backups are what you need here, which is why
   step 6 is gated on verification rather than done in the same breath as step 2.
4. **When the system is healthy again**, finish step 6. A retired credential left on disk is the
   next reader's trap.

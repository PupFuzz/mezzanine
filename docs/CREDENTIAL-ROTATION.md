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
line.

⚠ **"Not in `argv`, not in shell history" is not the whole of it, and the gap is a file on disk.**
The `mariadb` client keeps a history of its own and filters **nothing** out of it, so a statement
typed at its prompt is written to disk in plaintext unless you turn that off. Step 1 turns it off and
says how; step 7 cleans up after the run where you forgot.

⛔ **A statement that fails on its SYNTAX quotes itself back, so any statement here that carries a
value can print that value.** This is how the server answers a syntax error and not a property of
any one step: the message returns your statement **from the point of the error**, for a bounded
span, and whatever value sits inside that span comes back in the text. So *which* value you get is
decided by *where* you slipped. Measured 2026-09-21 UTC on a throwaway server of the version this
host runs, obvious fixtures only, each row against the correctly-spelled statement as its control —
the correct statement raises no error and therefore returns no message at all, and a failing
statement that carries no value (a mistyped `SHOW CREATE USER`) returns none either, so the
measurement discriminates. One mistyped keyword at the head of step 1's statement returns the
**old** password — the value every consumer is holding at that moment. The same statement slipped
one line lower returns the **new** one, as do step 6's, the single-value form, and a `SET PASSWORD`;
the ⭐ variant returns the hash it carries, cut off partway. **The rule is therefore the statement
and not the site: any statement here that carries a value can print it, and a syntax error is what
makes it do so.** So read every error one of these statements returns *before* you do anything else
with it, and treat whatever it showed as printed — a value that has been printed is an **exposure**,
and § "The outage window is avoidable" is where this document says what an exposure trigger changes.

⚠ **Redirect the input and the client adds its own copy, ahead of the server's.** Typed at the
prompt, the server's message is all that comes back. Run the same statement with input redirected —
a heredoc, a script, `<file`, a pipe — and the client echoes the **whole statement**, every value in
it, before the message. Measured both ways, same statement, same server. This is why step 1 says to
type these at the prompt rather than feed them to the client.

⚠ **A command below prints a secret when it SUCCEEDS, and it is needed.** `SHOW CREATE USER`, in
step 1, prints the account's password **hashes** — which are secret values, the same way `SHOW
GRANTS` and `SELECT … FROM mysql.user` are, and neither of those two is needed anywhere here. Step 1
needs `SHOW CREATE USER` for a reason it gives, so read that output on the screen, keep it out of
any log or paste, and put it nowhere but the statement it is for. That is what it prints when it
**works**; the ⛔ above is what a statement prints when it **fails**. Those are two different
questions. Some commands do *resolve* a value and send it nowhere — the classifier at the top of
this file puts the live value on a file descriptor rather than in `argv`, which is the whole of why
it is shaped that way, and step 2's editor puts it on your screen — and what matters is the stream,
not the resolution. The classifier's *failure* modes were measured on the same day and for the same
reason, since it reads the live value in order to compare it: a reference file missing, unreadable
or empty, an unreadable file in the sweep, a tree root that is not there — each names a **path** and
none reproduces the value, while the same detector fires on a stream that does carry it.

**A command that merely CONNECTS fails with the database user and host, and no value.** Every
connection this document makes is made by one of two things, the `mariadb` client or PHP, and both
were measured on the same throwaway: the client answers
`ERROR 1045 … for user '<user>'@'<host>' (using password: YES)`, and PHP answers
`SQLSTATE[HY000] [1045]` naming the same two fields. The value does not reach a stack trace either,
because the driver marks that parameter sensitive: measured both ways round the setting that would
otherwise put call arguments in a trace, and the parameter is withheld under either. **Read those
failures on the screen; do not paste them** — what they carry is the account and the host. The
statements that carry a value are the other class, and the ⛔ above governs them.

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

⚠ **Expect the `OTHER` list to be longer than the traps in it.** Re-derived independently during
this document's review (2026-09-20, `card#9660`), it also carries runbooks and deployment notes that
quote a placeholder `DB_PASSWORD=` line and agent tool-result transcripts under `~/.claude` that
captured one — all `OTHER`, none matching the live value. There is no per-path list here on purpose:
that set turns over faster than this file does. Read the path, apply the rule above, move on.

A second sweep catches a credential held in some shape other than a `KEY=value` line — a PHP-FPM
pool injecting `env[DB_PASSWORD]`, a `mysqli.default_pw` in a `php.ini`, a `~/.my.cnf`. It printed
nothing on the sandbox host on 2026-09-20, and it is not scheduled, so run it:

```sh
# ⚠ Read the VERDICT LINE, never the exit status: `xargs` answers 123 both when `grep` matched
# nothing and when it could not read a file, so a clean sweep and a sweep that failed are the same
# number. This prints which one it was.
hits=$(find ~/etc ~/domains -type f -print0 |
  xargs -0 grep -lIE 'env\[DB_|env\[MYSQL|^[[:space:]]*mysqli\.default_pw[[:space:]]*=[[:space:]]*\S' 2>/dev/null)
if [ -n "$hits" ]; then printf 'FOUND — each of these holds a credential-shaped directive:\n%s\n' "$hits"
else echo 'CLEAN — no credential-shaped directive under ~/etc or ~/domains'; fi
for f in ~/.my.cnf ~/.mylogin.cnf; do
  if [ -e "$f" ]; then ls -la "$f"; else echo "CLEAN — no $f"; fi
done
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

The sweep on 2026-09-20 found sibling files beside `~/agent-webhook-bridge/.env` — two `.bak`
copies, one timestamped and one not, and an editor's `.env~` — each holding a **non-empty**
`DB_PASSWORD` the sweep answers
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

⚠ **That is established for the overlap form of S and NOT for the single-value form, and the
difference is a security fact rather than a footnote.** The table below records the unprivileged
account being refused when it runs the overlap `ALTER USER` against itself. The **same** fixture
account — `ALL` on its own schema, `USAGE` on `*.*`, no `CREATE USER`, which is the shape `card#9660`
records for this host's application account — changed its **own** password with
`SET PASSWORD = PASSWORD('<new>')`, and the new value authenticated at once while the old was refused
at once (measured 2026-09-20 on the throwaway server described below, obvious fixtures only). So the
single-value server-side change may need no administrator at all on an account shaped like this one —
and the security half of that is that **anything able to read `server/.env` can rotate the shared
login and lock the bridge out**, which is this document's own 38-hour harm reachable from a file
read. This document's review measured a further consequence: a self-`SET PASSWORD` run during an
overlap replaces rule 1 and leaves rule 2 standing, so it silently half-undoes step 1. **Not
established for the live account**, whose privileges no seat here can read. The conclusion is
unchanged either way — S first.

Work the argument on the simplest form of the rotation — one value replaced by another — because
that is where it is cleanest. The subsection below removes the outage and leaves the order exactly
as this argument puts it.

**C then S**: every consumer now names a password the server has never heard of, so everything is
down **for the whole duration of a gate you have not yet passed** — and if that gate is then
refused, your only route back is step 0's backups, whose sufficiency you have not yet tested. The
server cannot tell you what the old value was; it holds a hash. S-first proves you hold the access a
rollback needs *before* anything is overwritten, and it never depends on a backup being good.

**S then C**: the server accepts only the new value, so everything is down for the same window —
but you hold that value, and every remaining step is a local file write on files you own, with no
gate left that can refuse. And if C turns out to be impossible, S can be re-run, *because step 1
just proved you have the access to re-run it*. Doing the refusable part first is the only order
that establishes you can get back **before** anything irreplaceable is overwritten.

### The outage window is avoidable: this engine holds two passwords at once

⛔ **Ask why you are rotating before you choose this path, because the trigger decides which path is
right.** The overlap removes the outage by keeping the **old** value accepted by the server until
step 6 retires it — and step 6 is gated on every consumer being healthy, a gate that can stay unmet
for as long as it takes to satisfy. For a **scheduled** rotation that is strictly better: the window
costs nothing and can be as long as you need. For a rotation triggered by **exposure** — a value that
was printed, pasted, committed, logged, or left readable somewhere another party could reach — it is
the wrong trade, because the window is a window in which the possibly-compromised value still works,
and the rotation has not actually happened until step 6 does. On that trigger: take the single-value
path and accept its outage, or run steps 2 through 6 back to back and treat **step 6**, not step 1,
as the moment the credential is rotated. Exposure is not a hypothetical trigger on this host — the
section above records superseded values still readable on disk, and the client history file step 1
warns about is one more surface the procedure creates for itself.

⭐ **MariaDB accepts more than one authentication rule for a single account, each rule carrying its
own password, and a client presenting *either* value authenticates.** The grammar is
`IDENTIFIED {VIA|WITH} authentication_rule [OR authentication_rule ...]`, where a rule may be
`authentication_plugin USING PASSWORD('password')`; and *"If more than one authentication mechanism
is declared using the `OR` keyword, the mechanisms are attempted in the order they are declared in
the `CREATE USER` statement. As soon as one of the authentication mechanisms is successful,
authentication is complete."* — MariaDB documentation,
[CREATE USER § Authentication Options](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/create-user),
read 2026-09-20. The transitional use is stated outright in
[Authentication from MariaDB 10.4](https://mariadb.com/docs/server/security/user-account-management/authentication-from-mariadb-10-4):
*"It is possible to use more than one authentication plugin for each user account … while allowing
the old … authentication plugin as an alternative for the transitional period."*

**Documented is not measured, so it was measured** — 2026-09-20, against a throwaway `mariadbd`
started in a scratch datadir with `--skip-networking` and its own socket, reporting
`11.8.6-MariaDB`: the version this fleet pins
([`docs/design/FLEET-STATE.md § 6.1`](design/FLEET-STATE.md#61-deployment-posture)) and the version
this host runs. The live server was never touched and every value below was a throwaway fixture.

| What was exercised | Result |
|---|---|
| `ALTER USER … IDENTIFIED VIA mysql_native_password USING PASSWORD('<old>') OR mysql_native_password USING PASSWORD('<new>')` | accepted — **two rules naming the same plugin are legal**, and `SHOW CREATE USER` prints both |
| connecting with the old value, then the new | both authenticate; a **third, wrong** value is refused with `ERROR 1045`, so the test discriminates rather than passing everything |
| the same three values through **PHP PDO / mysqlnd** (PHP 8.5.4) — how both consumers actually connect | old ✓, new ✓, wrong ✗. The second rule is reached by this stack's own client, not only by the `mariadb` CLI |
| `ALTER USER … IDENTIFIED VIA mysql_native_password USING PASSWORD('<new>')` alone | the old value is refused at once and the new one still works — *"not specifying an authentication option in the `IDENTIFIED VIA` clause will remove that authentication method"* ([ALTER USER](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/alter-user)) |
| the same overlap `ALTER USER`, run **by the unprivileged account on itself** | refused: `ERROR 1227 … you need (at least one of) the CREATE USER privilege(s)`. **An overlap does not let a consumer rotate itself** — S stays the administrative, refusable half, so the order above is unchanged |

⇒ **The outage is not inherent. Add the new password beside the old, move every consumer across
while both work, then retire the old.** That is the shape this repository already uses for its other
shared credential:
[`docs/design/EVENT-SCHEMA.md § 3.3`](design/EVENT-SCHEMA.md#33-authentication-and-the-identity-binding-rule)
issues the fleet token server-side first with both values valid for a seven-day overlap, then writes
the new one into the consumer, then revokes the old. Same order, same reason, and the procedure
below is that doctrine applied to this credential.

⚠ **Two conditions, and if either fails you are on the single-value path.**

* **The server-side route must be SQL as an administrator.** A hosting-panel password box sets one
  value and cannot express a second rule.
* **This was measured on a private server of the same version, never on the live account** — whose
  administrative half is behind a credential no seat here holds. The first real rotation is what
  establishes it there, and step 1's `SHOW CREATE USER` is the check that says whether it took.

**On the single-value path the window is real, and the order is what makes it survivable rather than
what shortens it.** Shorten it by having C staged before you touch S — every path from the
derivation in hand, every file confirmed writable, the editor open. Then the gap is seconds rather
than however long it takes you to go looking.

⚠ **An agent seat does not run this procedure.** The `.env` write is gated there as a secret-store
write, and that denial is correct — it is what stopped `card#9660`'s instance fix until an operator
took it. An agent prepares the enumeration and hands it over; the operator rotates.

---

## The procedure

**0 — Stage everything C needs.** Run the derivation and the second sweep at the top of this file.
Every path answering `LIVE` is a file you must update. Confirm each is writable by you (`ls -l` on
the paths), and take a temporary backup of each, mode 600, in one directory **outside both
checkouts** — a backup left beside the original is what the next sweep finds and what the next
operator restores. That backup is the copy of the old value a rollback needs — and on the overlap
path step 1 needs it too, because it restates the old password. **Step 7 deletes them**, and
skipping that step is how the `.env~` and `.bak` files above came to exist.

**1 — S₁, the refusable part: add the new password *beside* the old.** As an administrative user, at
the prompt, never with `-e`, and with the client's own history turned off:

```sh
sudo env MYSQL_HISTFILE=/dev/null mariadb
```

⛔ **Do not paste the statement below first — read the blocks under it, and read `SHOW CREATE USER`
before you write it.** One of those blocks carries a failure this procedure's own checks cannot
discriminate: on an account that already holds more than one authentication rule, the statement
below is **accepted**, silently drops every rule it does not name, and step 1's own "two rules are
present" confirmation passes either way.

```sql
ALTER USER '<the account DB_USERNAME names>'@'<its host>'
  IDENTIFIED VIA mysql_native_password USING PASSWORD('<the old password>')
            OR mysql_native_password USING PASSWORD('<the new password>');
```

⛔ **`MYSQL_HISTFILE=/dev/null` is not decoration: without it this statement writes BOTH plaintext
passwords to a file on disk.** Keeping a value out of `argv` and out of your *shell* history is not
the same as keeping it off disk — the `mariadb` client keeps a history of its own and filters
nothing. Measured 2026-09-20, client 15.2 against server 11.8.6, driving the client under a pty with
`HOME` pointed at a scratch directory: without the variable the whole `ALTER USER` lands in
`~/.mariadb_history`, both values readable verbatim; with it in front, **no history file is created
at all**. That is a control run both ways, not an assurance. `IDENTIFIED BY` and `SET PASSWORD` are
recorded the same way, so this applies to every statement in this document that carries a value,
step 6 included. And it matters more here than the usual tidiness argument: the file lands in the
`HOME` of whoever invoked the client — root's, under `sudo`'s usual `env_reset` — so it is **neither**
a `DB_PASSWORD=` line **nor** under this account's `~`, and **neither sweep at the top of this file
can ever find it**. It is this document's own opening defect, in the one place this document's own
instrument is blind to.

⚠ **The old value has to be restated, and that is the whole clause doing its job**: an `ALTER USER`
that does not name an authentication method *removes* it. Step 0's backups hold the old value.
Confirm with `SHOW CREATE USER '<the account>'@'<its host>'` that **two** rules are present — that
output carries password hashes, so read it and keep it out of any paste or log.

⚠ **Read that same `SHOW CREATE USER` first, before you write the statement.** The rule above names
`mysql_native_password` because that is what an account created with `IDENTIFIED BY` uses. An
account already on another plugin keeps *its* rule as the first one, verbatim, and the new value
goes in the second — writing `mysql_native_password` over a plugin the account was using changes how
it authenticates as a side effect of a password change.

⛔ **And if that output prints MORE than one rule, every rule you do not restate is removed, and it is
removed silently.** The statement above names exactly two rules and so does the ⭐ variant below it:
both are written for the single-rule account this document expects, and neither is safe on an account
that already carries two. Measured 2026-09-21 UTC on a throwaway server of the same version: against
an account reading `IDENTIFIED VIA unix_socket OR mysql_native_password USING '<hash>'`, the statement
is **accepted, rc 0**, the account becomes `mysql_native_password OR mysql_native_password`, and socket
authentication that worked a moment earlier is refused `ERROR 1045`. **The "two rules are present"
confirmation above cannot tell you this happened**, because it was already two — measured both ways
on the same fixture, two before and two after. An account with rules to keep needs a statement that
carries all of them, and this document does not have a measured one to give you: write it from that
output, rule by rule, and prove each surviving rule still authenticates before you go on.

⭐ **Better: do not retype the old value at all, and let that reading write rule 1 for you.** Rule 1
can carry the **hash** `SHOW CREATE USER` just printed rather than a plaintext, copied into the
statement in that same session and nowhere else. **What you copy depends on the shape that output
took, and for the single-rule account this document expects, the difference between the two shapes it
can take is a syntax error rather than a nuance:**

* **The output names a plugin** — `IDENTIFIED VIA ed25519 USING '<…>'`. **Copy that clause verbatim
  as rule 1.** This is the case where the account's existing plugin comes across by construction
  instead of asking you to notice it.
* **The output names no plugin** — `IDENTIFIED BY PASSWORD '*<40 hex>'`, which is what an account
  created with `IDENTIFIED BY` prints and therefore the shape to expect here. **That clause is not
  valid inside an `OR` form**: pasting it is refused `ERROR 1064` and nothing changes. ⚠ **Here the
  document presents a failure as an expected outcome, so read what the rule at the top of this file
  says that failure prints** — the error falls on rule 2, so the span the server quotes back carries
  your **NEW** password in plaintext: the message ends `…near 'OR
  mysql_native_password USING PASSWORD('<the new password>')' at line 1`. Read it; do not paste it,
  and do not run this statement through a redirect. The one stream it was measured to stay out of is
  the server's error log (`grep` for the value on a throwaway's `--log-error` file, with the same
  file answering a control term). The plugin is
  `mysql_native_password`, and rule 1 is
  `IDENTIFIED VIA mysql_native_password USING '<that same hash>'` — the hash it printed, re-spelled
  as `VIA … USING`.

```sql
ALTER USER '<the account DB_USERNAME names>'@'<its host>'
  IDENTIFIED VIA <mysql_native_password, unless the output named another> USING '<the hash it printed>'
            OR mysql_native_password USING PASSWORD('<the new password>');
```

Measured 2026-09-20 on a throwaway server of the same version, for both shapes: the statement above is
accepted, both the old and the new value authenticate, and a third value is refused `ERROR 1045` —
which is what makes the pass mean something. The verbatim paste of `IDENTIFIED BY PASSWORD '<hash>'`
into the `OR` form is the `ERROR 1064`, measured the same way. **What this form removes is the
retyping of the old value** — retyping it is where a typo takes every consumer down at once. It does
not make the statement un-mistypable, and the fence invites a mistyping the server ACCEPTS: rule 2
legitimately reads `USING PASSWORD('<the new password>')`, and writing `USING PASSWORD('<hash>')` in
rule 1, one line above, where it wants a bare `USING '<hash>'`, is **accepted, rc 0** — it hashes
your hash. Measured 2026-09-21 UTC against a freshly re-created fixture account, with the correct
spelling as the control on the same fixture: correct, the old value still authenticates; mistyped,
`SHOW CREATE USER` still prints **two** rules, the new value works, and the value every consumer
holds is refused `ERROR 1045` — the whole outage the overlap exists to remove, from one extra token.
The ⛔ check below is what catches it. The cost is a secret hash on your
screen and — if you skipped `MYSQL_HISTFILE=/dev/null` — in the client's history file.

⛔ **On the overlap path, before you touch a single `.env`, prove a consumer still authenticates on
the OLD value.** The overlap `ALTER USER` replaces **both** rules atomically, so if rule 1 is not the
value your consumers actually hold — on either path, and each path has its own way of getting there:
a typo or the wrong backup file where you restated the password (§ "A stale copy of a retired
password is a trap" is about the superseded values this host has readable on disk), or the accepted
`USING PASSWORD('<hash>')` mis-spelling where you copied the hash — then rule 1 no longer
matches what any consumer holds and **every consumer is refused immediately**: the full outage the
overlap exists to remove, arriving unannounced and with nothing between here and step 2 to announce
it.

**The `SHOW CREATE USER` check above does not discriminate this.** Measured during this document's
review (2026-09-20, `card#9660`) on a throwaway server with the old value deliberately mistyped: the
statement is **accepted**, the output prints **two** rules, and the value the consumers actually hold
is refused `ERROR 1045`. Two rules being present says the statement parsed — not that rule 1 is the
value anybody holds. What discriminates is a consumer:

```sh
cd ~/mezzanine/server && php artisan migrate:status   # a checkout you have NOT yet edited
```

rc 0 is what makes the next paragraph true. rc 1 carrying `SQLSTATE[HY000] [1045]` means rule 1 does
not match what your consumers hold: **go back and re-run step 1 with the right old value, and do not
continue** — you are one step from writing files while everything is already down. Run it in a clean
shell, because an exported `DB_PASSWORD` beats the file and would answer for the value rather than
for the file (§ Verifying), and read its failure text rather than pasting it — it carries the
database user and host.

**With that rc 0 in hand, nothing is broken.** Every consumer still authenticates with the value it
already holds, and each one authenticates the moment it moves to the new value. That is what removes
the window, and it is why step 6 exists.

⛔ **If the server, or your route to it, will not take the two-rule form** — a hosting panel with one
password box, an `ALTER USER` refused — then this is the single-value path: the statement is
`ALTER USER … IDENTIFIED BY '<the new password>'`, **everything that connects from here on is broken
until step 2 reaches it**, and that outage is chosen deliberately rather than a surprise, with the
new value in your hand rather than out of it. The order does not change; only the window does, and
there is then no step 6 because step 1 already retired the old value.

Connections that are already open are **not** dropped by either form — authentication happened when
they were made. That is why step 3 restarts the long-lived daemons rather than trusting the outage to
announce itself.

**2 — C, in one act, using step 0's list as the checklist.** Edit `DB_PASSWORD` in every DIRECT
consumer — the application's, the bridge's, and any worktree copy you are keeping (delete the ones
you are not). Use an editor. A `sed -i` whose pattern carries the value puts it in argv.

**3 — Make the edit take effect.** An edited file is not a running process.

| Consumer | Does the edit alone take effect? | Do this |
|---|---|---|
| PHP-FPM, either vhost | yes, on the next request — Laravel reads `.env` at boot — **unless a config cache exists**, in which case the file is inert until it is rebuilt. Check: `ls server/bootstrap/cache/config.php`, and the bridge's | run `php artisan config:clear` in **both** checkouts. It is a no-op where there is no cache and the whole fix where there is one |
| `schedule:run` | yes, next minute | nothing |
| the long-lived daemons | **no** | stop each by its lock file — `fuser -k -TERM <lock>` — and cron restarts it within 60 s. `bin/supervision.sh` owns this recipe, and its `supervision_lock` is what turns a daemon's name into its lock path — step 6's loop calls it, so take the path from there rather than writing one out; its header's "moving a hand-staged crontab" walks the same stop-by-lock step |
| a worktree you kept | yes, next run | nothing |

⚠ **Why the daemons are the dangerous row.** Each holds a connection opened before the rotation.
The server does not close it, so the daemon keeps working and the break is *deferred* to whenever
that connection next drops and it reconnects with what it read at start — hours later, in its own
log, looking unrelated to anything you did. Restarting removes the question rather than answering
it. **On the overlap path this row is more dangerous, not less**: a daemon that was never restarted
reconnects with the *old* value and succeeds, so nothing is wrong until step 6 retires it — and then
it breaks, an hour after the rotation you thought was finished, which is this document's own harm
with a delay fuse on it.

**4 — Verify every consumer, not the one you were thinking about.** Next section.

⛔ **During the overlap, a successful connection stops telling you which value the consumer used.**
Both are accepted, so every connection check in the next section passes for a consumer still on the
old value — including the bridge watcher. The overlap buys the absence of an outage and costs this:
**step 5 is what discriminates during the window for a consumer whose credential is in a FILE, and
it is not optional.**

⚠ **It discriminates for nothing else, and closing that gap is step 6's gate, not step 5's job.**
Step 5 re-runs the derivation, and the derivation reads files. Two consumers hold the credential
where no file sweep reaches, and both are named in this document already:

* **a long-lived daemon that read `.env` before you started** — the delay fuse in step 3's warning.
  Its file on disk is correct and its memory is not, so step 5 sees nothing wrong;
* **a Laravel config cache.** `server/config/database.php` resolves `env('DB_PASSWORD')` into config,
  so a built `bootstrap/cache/config.php` holds the value as a PHP array element — **not** a
  `DB_PASSWORD=` line, therefore invisible to a content sweep by construction, and inert to the
  `.env` edit until it is rebuilt. Latent rather than live on this host today: neither checkout
  carries one, which is one `ls` to confirm and step 3 already prescribes.

Both keep working for the whole overlap and both break at **step 6** — an hour after the rotation you
thought was finished. That is why step 6 is gated on *evidence that step 3 actually happened* and not
on the absence of complaints. Step 6 is also the only thing that makes a connection check meaningful
again.

**5 — Re-run the derivation.** Every consumer you just updated reads `LIVE` again. Anything still
reading `OTHER` is either a file you missed or a retired copy that wants deleting — read the path
and decide which. On the overlap path this is the check that catches a consumer you skipped, because
the skipped one is still working.

**6 — S₂: retire the old password.** Overlap path only, and only once every one of these holds:

* step 4 says every consumer is healthy;
* step 5 says every stored copy is on the new value;
* **every long-lived daemon started AFTER you edited the files in step 2** — positive evidence, not
  an absence of complaints. Read step 2's moment off the file rather than out of your memory, and
  each daemon's start time off the process that holds its lock:

  ```sh
  cd ~/mezzanine && . bin/supervision.sh   # bash; defines the helper the loop calls, runs nothing
  ls -l --time-style=full-iso server/.env  # ← when step 2 wrote it, as a fact rather than a memory
  for d in $(bin/supervision.sh daemons); do
    pids=$(fuser "$(supervision_lock "$PWD" "$d")" 2>/dev/null |
           tr -s ' ' ',' | sed 's/^,//;s/,$//')
    echo "== $d"
    if [ -n "$pids" ]; then ps -o pid,lstart,args -p "$pids"
    else echo "   nothing holds its lock: not running, so it carries no copy"; fi
  done
  ```

  `bin/supervision.sh daemons` prints the supervised **commands**, not lock paths; `supervision_lock`
  is the function that turns one into the other, and the loop calls it rather than restating its rule,
  so a daemon added later — or a lock path moved — is covered without editing this file. Each lock is
  held by **two** processes, the `flock` wrapper and the `php artisan` child it started, and both
  carry the daemon's start time. A daemon whose `STARTED` is older than the `.env` mtime is still
  authenticating on the old value, and this statement is what breaks it: go back to step 3 and restart
  it. A lock nobody holds means that daemon is not running, so it carries no copy to go stale and cron
  starts it fresh on the new value. Both branches were run on this host, 2026-09-20 — the second
  against a lock deliberately left unheld, so the message is a measurement rather than a guess;
* **`php artisan config:clear` has been run in BOTH checkouts since step 2** — or
  `ls server/bootstrap/cache/config.php`, and the bridge's, shows there was no cache to clear.

The last two are precisely what step 5 cannot answer, and skipping them is how a rotation that looked
finished breaks an hour later. Then, at the same prompt as step 1 and with the same
`MYSQL_HISTFILE=/dev/null`:

```sql
ALTER USER '<the account DB_USERNAME names>'@'<its host>'
  IDENTIFIED VIA mysql_native_password USING PASSWORD('<the new password>');
```

Naming only the new rule is what removes the old one. Verify once more afterwards: the old value is
now refused and the new one still works, which is the rotation finished rather than merely started.

⚠ **Verify that by connection, never by the printed shape.** After S₂ `SHOW CREATE USER` prints the
single-rule `IDENTIFIED BY PASSWORD '<hash>'` form rather than an `IDENTIFIED VIA … OR …` one
(measured 2026-09-20) — that is the retire having worked, not having failed.

This step is administrative and therefore refusable too — and a refusal here costs nothing, because
every consumer is already on the new value. That asymmetry is the reason the refusable half is split
in two: the expensive refusal is moved to a moment when nothing is broken yet, and the cheap one is
all that is left at the end.
**Skipping this step leaves a retired credential the server still accepts** — worse than one lying
in a `.bak` file, because no file sweep can see it.

**7 — Delete step 0's backups, and the client history file.** Verification is what gates this: once
every consumer is confirmed healthy and the old value is retired, the backups have no remaining job,
and a retired secret readable on disk is a surface.

⛔ **And if you ran step 1 or step 6 without `MYSQL_HISTFILE=/dev/null`, delete the history file of
whichever account ran them** — `~/.mariadb_history` (and `~/.mysql_history`), root's if you used
`sudo`. It holds, in plaintext, every value those statements carried, and **neither sweep in this
document can see it**: it is not
a `DB_PASSWORD=` line and it is not under this account's home. Nothing else in this procedure will
ever find it for you — and that is the general rule the sweeps at the top cannot express: they find a
password stored as a `DB_PASSWORD=` line under this account and **nothing else**, so any file a tool
wrote on your behalf during the rotation is yours to remember rather than theirs to catch.

---

## Verifying

| What | Command | What a pass proves — and what it does not |
|---|---|---|
| the application reaches the store | `cd ~/mezzanine/server && php artisan migrate:status` | rc 0 means this checkout's `.env` is accepted by the server. **Seen to fail** (2026-09-20): rc 0 healthy, and rc 1 carrying `SQLSTATE[HY000] [1045]` when the same command is run with a deliberately wrong password — so a pass here is evidence rather than decoration. ⚠ the failure text carries the database user and host: read it, do not paste it |
| the application is serving | `curl -sS -o /dev/null -w '%{http_code}\n' "$APP_URL/up"` | 200 means the app answers. **It says nothing about the store** — `/up` needs no credential (`bin/deploy.sh`'s own smoke step says so). Reading a green `/up` as a healthy database is exactly the false confidence this document exists to remove |
| the daemons came back | `cd ~/mezzanine && . bin/supervision.sh` (bash), then `for d in $(bin/supervision.sh daemons); do echo "== $d"; tail server/storage/logs/daemon-"$(supervision_daemon_name "$d")".log; done` — the subcommand prints `mezzanine:fold` and the file is `daemon-fold.log`, so the loop calls the function that owns that transform rather than restating it, the same way step 6's loop takes its lock paths from `supervision_lock` | a fresh line after the restart, with no access-denied, means that daemon reconnected on the new value. ⚠ when it is **not** clean, what you are reading is a PDO failure carrying the database user and host: read it, do not paste it |
| the bridge reaches its store | `P=$(mktemp -d); BRIDGE_DB_WATCH_STATE=$P/state BRIDGE_DB_WATCH_LOG=$P/log ~/.local/bin/bridge-db-watch.sh; echo rc=$?; rm -rf "$P"` — `mktemp -d` rather than a fixed `/tmp/probe.state`, because the watcher writes with `>`, which follows a symlink anybody else on the host could have planted at a predictable path | rc 0 is the bridge's own `DatabaseConnectivityCheck` reporting ok, immediately, without waiting for cron and without disturbing the live state file. rc 1 is `FAILING`, rc 2 is `UNMEASURED` — which is **not** a pass |
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
* **The overlap has never been exercised on the live account.** That this engine holds two
  concurrently-valid passwords for one account, and that this stack's own PHP client authenticates
  with either, is measured rather than assumed — but on a private server of the same version
  (§ "The outage window is avoidable"), because the live account's administrative half is behind a
  credential no seat here holds. The first real rotation is what establishes it there, and step 1's
  `SHOW CREATE USER` is the check that says whether it took.
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
   step 7 is gated on verification rather than done in the same breath as step 2. On the overlap
   path there is a cheaper answer first: the old value still works, so revert the consumer's file
   and nothing is down while you work out what the server rejected.
4. **When the system is healthy again**, finish steps 6 and 7 **on the overlap path** — retire the
   old value at the server, then delete the backups and the client history file. On the single-value
   path there is no step 6, because step 1 already retired the old value, so only step 7 is left. A
   retired credential left on disk, or left accepted by the server, is the next reader's trap.

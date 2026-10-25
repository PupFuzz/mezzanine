# Installing the Mezzanine reporter — the guide for a Claude Code agent

**You are a Claude Code agent, and the user has asked you to install the Mezzanine reporter on this
machine.** Follow this page from top to bottom. It needs no coordination framework, no root and no
`sudo`, and it works on Linux only. At the end the user's Claude Code activity on this account reaches
their Mezzanine site, where it appears as a desk on the floor.

The reporter, `fleet-reporter`, is a set of Claude Code hooks plus one background process. The install
copies it into this account's home, writes its config, adds its hooks to `~/.claude/settings.json`
(every hook already there is kept, in place), and adds one block to the account's crontab to keep the
background process running. [`INSTALL-LINUX.md`](INSTALL-LINUX.md) owns every command that does this;
[`install.sh`](install.sh) runs those commands for you, with a backup of each file first. Run the script
and let it do the work, so that every change it makes is backed up and checked.

## The seat token is the one secret, and the user enters it

The site issues the seat a token, `mzn_…`, that authenticates every batch the seat sends. **It must
never pass through you.** Do not ask the user to paste it into the chat, and do not put it in a command,
an environment variable or a file. The user types it at a prompt `install.sh --set-token` shows, which
echoes nothing and writes the token straight into the config with mode `0600`. Your own shell has no
terminal, so that command refuses to run from your Bash tool: it is the user's to run (step 5).

## 1. Check the machine

```bash
uname -s && git --version
```

It must print `Linux`. On macOS or Windows, stop and tell the user that this install is Linux only;
`INSTALL-LINUX.md` names what is owed for Windows. `install.sh` checks `node` (18 or later), `flock` and
`crontab` itself, and names any that is missing.

## 2. Get the code

```bash
git clone --depth 1 https://github.com/PupFuzz/mezzanine "$HOME/.local/src/mezzanine"
```

If that directory is already there from an earlier install, update it instead with
`git -C "$HOME/.local/src/mezzanine" pull --ff-only`. The commands below write the script's path as
`$HOME/.local/src/mezzanine/fleet-reporter/install.sh`; if the user wants the checkout elsewhere, use
that path throughout.

## 3. Ask the user for the values, in one message

| Value | What to tell the user | Flag |
|---|---|---|
| **Site URL** | the address of their Mezzanine site, as they open it in a browser: `https://<host>` | `--site` |
| **Seat name** | a name for this seat, shown on its desk: lowercase letters, digits and `-`, 2 to 48 characters, starting with a letter or digit. Offer one, such as this machine's host name in lowercase | `--seat-id` |
| **Install id** | the group this seat belongs to on the site, 2 to 32 characters in the same alphabet. Without it the script uses this account's name (`id -un`, in lowercase); offer that, and say they can name an install the site already has | `--install-id` |

Two more values are optional, and most users need neither:

- **`--agent-name`**, only for a user whose seat runs the coordination framework with a roster, to
  declare the seat's name in it. Without it the seat is *undeclared*, which is a supported state that
  stays so ([`docs/design/EVENT-SCHEMA.md`](../docs/design/EVENT-SCHEMA.md) § 3.1, item 4 of
  `protocol_agent_name`): the desk reports in full, and only coordination posts are not joined to it.
- **`--ca-file`**, only when the site's certificate is issued by a private CA: the absolute path of a PEM
  file that trusts it.

## 4. Run the first command

```bash
bash "$HOME/.local/src/mezzanine/fleet-reporter/install.sh" --site <site URL> --seat-id <seat name> --install-id <install id>
```

With no config yet it installs nothing, exits `3`, and prints the token step with these values filled
in: where to issue the token, and the exact `--set-token` command. An exit `1` names a value it refused,
such as a seat name outside the alphabet; ask the user again and re-run.

## 5. Hand the token step to the user, and wait

Relay the two steps the script printed, in its words, and add that you will wait:

1. **Issue the token on the site.** The user signs in to the site as an operator, opens the admin
   console's *Seat tokens* page, `<site URL>/admin/tokens`, which asks for their password again, types
   the install id and the seat name exactly as above, and issues. The page shows the token once, with
   its prefix. The prefix is not secret; ask them to keep it, because revoking the token later takes it.
2. **Enter it at the hidden prompt.** The user runs the `--set-token` command the script printed. In
   Claude Code they can type it after `!` at the prompt. Claude Code can run a `!` command without a
   terminal: the script then prints `there is no terminal to read the token from` and writes nothing,
   and the user runs the same command in a terminal window of their own on this machine, signed in as
   the same account. At the prompt they paste the token and press Enter; nothing is echoed.

Do not go on until the user says it is done. Success ends with `the token is in the config`, after
Step 3's check of the config, which shows the token only as `<well-formed>`. If the script printed `does
not hold exactly one well-formed mzn_ token`, the paste was something else: the token file is already
removed, and the user runs the command again and pastes the token again.

## 6. Run the install

```bash
bash "$HOME/.local/src/mezzanine/fleet-reporter/install.sh" --site <site URL> --seat-id <seat name> --install-id <install id>
```

It takes one to four minutes: it waits for the background process's first start and for the site to
accept a batch. It prints each runbook block it runs, then ends with:

```
install.sh: selftest rc=0 · health rc=0 · arrival rc=0
install.sh: INSTALLED: the server holds this build current and every site has accepted a batch; the desk is on the site's floor
```

and it exits `0`. Above those lines, the health check prints `default: installed <version>, server current
<version>: current`, which is the site saying it holds this build current, and the arrival check prints
`default: <n> batch(es) accepted: events arrive`.

## 7. Tell the user what to look at

The desk is on the site's floor under the seat name. It appeared, as *no data yet*, when the token was
issued, and it shows the seat's own state from the first batch the site accepted, which the arrival
check has just seen. A Claude Code session started before the install, this one included, may not
report its own tool calls until it is restarted; the background process reports the seat's liveness
either way, so suggest the user starts a new session to see work appear on the desk.

## 8. Running it again

Running step 6's command again is safe: on a seat that is already current it changes nothing, ends
`ALREADY CURRENT: nothing changed, and every check passed`, and exits `0`. The same command upgrades
the seat after `git -C "$HOME/.local/src/mezzanine" pull --ff-only`: it installs the new build, rewires
whatever the build adds, restarts the background process on it, and checks the site holds it current.
[`UPDATES.md`](UPDATES.md) lists what each reporter version changes; a version whose row lists a
`config` touch needs the change that row names, by hand.

## When a run does not end in `INSTALLED`

Read the block the script was running, which it prints above its last line, and match it here:

| What it printed | What it means | What to do |
|---|---|---|
| `is not a seat_id`, `is not an install_id`, `must be an https:// URL` | a value is outside its pattern | ask the user for the value again |
| `the config is for seat_id …` (or `install_id`, or `do not include`) | a config for another seat is already on this account | ask the user which seat this account is; this install does not replace a seat. `INSTALL-LINUX.md` Step 8 removes one by hand |
| selftest `token_accepted` `fail`, or health `HTTP 401 unauthenticated` | the site does not accept the token: mistyped, revoked, or issued on another site | the user issues a new token on the Seat tokens page and runs the `--set-token` command again, which replaces it; then they revoke the old prefix there, and you run step 6 again |
| arrival `a batch was REFUSED`, with `403 identity_mismatch` in the file it names | the token was issued for another install id or seat name | as the row above, issuing the token for exactly the install id and seat name of step 3 |
| health `NOT CURRENT` | this checkout and the site run different builds | an installed build older than the site's: pull the checkout and run step 6 again. Newer: the site's operator deploys the newer build; the seat reports either way |
| `NOT MEASURED` from health, or arrival `NOT ARRIVED`, and exit `2` | the site did not answer, or no batch arrived within the wait | check the site URL opens in a browser, wait a minute, and run step 6 again. `INSTALL-LINUX.md` Step 6 and Step 7 read every code |
| `INSTALL-LINUX.md and this script disagree` | the checkout was edited, or is broken | clone it again (step 2) |

`INSTALL-LINUX.md` § Running it with `install.sh` states every exit code and what each run does.

## What this install leaves out

- **The status-line wrap** (Step 4 (b)), which adds the desk's context gauge at a cost to every status
  line render. It is optional and not applied; the runbook says how.
- **`roundtable_agent_name`**, for a seat that posts on the cross-project coordination channel: a hand
  edit of the config, in the runbook's table of values.
- **Uninstalling**, which is `INSTALL-LINUX.md` Step 8, by hand.

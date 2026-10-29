# Installing the Mezzanine reporter — the guide for a Claude Code agent

**You are a Claude Code agent, and the user has asked you to install the Mezzanine reporter on this
machine.** Follow this page from top to bottom. It runs on Linux, Windows (in Claude Code's Git Bash tool)
and macOS, needs no coordination framework, no root and no `sudo`, and asks the user for one thing: where
they saved or copied the seat's token file. At the end the user's Claude Code activity on this account
reaches their Mezzanine site, where it appears as a desk on the floor.

The reporter, `fleet-reporter`, is a set of Claude Code hooks plus one background process.
[`install.js`](install.js) installs it: it copies it into this account's home, writes its config, adds its
hooks to `~/.claude/settings.json` after every hook already there, and sets up what keeps the background
process running. [`INSTALL.md`](INSTALL.md) is the reference for what each step does and why.

## The token is the one secret, and it never passes through you

The site issues the seat a token, `mzn_…`, that authenticates everything the seat sends. The user saves it
as a file from the site's *Seat tokens* page, and you pass the installer **the file's path**, nothing else.
Never ask the user to paste the token, never put it in a command, an environment variable or a file of
your own, and never open the token file with your Read tool or print it: the installer is the only thing
that reads it, and it removes the file once the token is in the config.
If a reply from the user holds a string that starts `mzn_`, the token has passed through you: run nothing, tell the user so, and ask them to revoke its prefix on the Seat tokens page and issue another.

## 1. Check the machine and get the code

```bash
node --version && git --version
```

`node` must be 18 or later. Then clone the repository, or update it if an earlier install left it there:

```bash
git clone --depth 1 https://github.com/PupFuzz/mezzanine ~/.local/src/mezzanine || git -C ~/.local/src/mezzanine pull --ff-only
```

## 2. Ask the user the one question

Send this, with the two names filled in. **Propose the names**: the install id is this account's name as a
slug (`id -un`, lower-cased, `.` and `_` turned into `-`), and the seat id is this machine's host name
lower-cased (`hostname`); the user may type others.

> One thing I need from you: on your Mezzanine site, open **Admin → Seat tokens**, issue a token for this
> seat — install id `<install id>`, seat id `<seat id>`, unless you want other names — click **Save token
> file**, and tell me where you saved or copied the file on this machine (usually
> `~/Downloads/mezzanine-reporter-token`). Please don't paste the token itself: the installer reads the
> file and removes it once the token is in the config.

**On macOS** add: *"Save or copy the file into your home folder rather than Downloads, Desktop or
Documents; if it is in one of those and macOS asks whether the terminal may access that folder, allow
it."* **On Windows** pass the path the user gives with forward slashes (`C:/Users/me/Downloads/…`).

The file may have been saved on another machine and copied here: that is as good as one saved here.

## 3. Run the installer, in the background

```bash
node ~/.local/src/mezzanine/fleet-reporter/install.js --token-file <the path the user gave> > ~/fleet-reporter-install.log 2>&1; echo "install.js exit $?" >> ~/fleet-reporter-install.log
```

Run the installer in the background with its output to a file, and read the file's last lines: a foreground call is cut off by the Bash tool's timeout before the install ends.
That timeout is 120 s by default and 600 s at most (the Bash tool's own description, read 2026-10-29), and
the install's own bounded waits add up past it: up to 70 s for the background process's restart on the new
build, up to 150 s for the site to accept a batch, and a 15 s request per site for each of two checks —
over four minutes on one site before the copying. Start the command with your Bash tool's background
option, wait for it to finish, then read the end of the log:

```bash
tail -n 25 ~/fleet-reporter-install.log
```

## 4. Read the result and tell the user

Success ends with:

```
install.js: selftest rc=0 · health rc=0 · arrival rc=0
install.js: INSTALLED: the server holds this build current and every site has accepted a batch; the desk is on the site's floor
install.js exit 0
```

Tell the user the desk is on the floor as `<install id>/<seat id>`, and that a Claude Code session started
before the install, this one included, reports its tool calls once it is restarted. **On Windows** add that
nothing keeps the background process running when no Claude Code session is open, so the desk reads
offline then (the operator's ruling, Option A). An `exit 2` installed the reporter and could not measure a
check (the site did not answer in time): run step 5's re-check a minute later. An `exit 1` refused or
failed, and its last `install.js:` line says why; the table below reads the common ones.

**If you run the installer again after the token file was taken**, run it with **no `--token-file`**. A run
that failed before the config was written left the file in the config directory, and the re-run uses it
from there; a run that failed after the config was written has already removed the file, and the re-run
needs none. A second `--token-file` run finds the original path gone and refuses.

## 5. Upgrade, re-check and uninstall

*"Upgrade the Mezzanine reporter"* is `git -C ~/.local/src/mezzanine pull --ff-only`, then step 3's command
with no `--token-file`; it ends `ALREADY CURRENT` on a current seat and changes nothing.
[`UPDATES.md`](UPDATES.md) lists what each reporter version changes; a version whose row lists a `config`
touch names the change it needs. **Uninstalling** is the same command with `--uninstall` in place of
`--token-file <path>`; its first lines name each token prefix, which the user revokes on that site's Seat
tokens page.

## When a run does not end in `INSTALLED`

| The last `install.js:` line says | What it means | What to do |
|---|---|---|
| `an argument looks like a seat token` | the token went into the command line | the token is exposed: run nothing more, and have the user revoke its prefix and issue another |
| `cannot read the token file …`, `is not a regular file`, `is a symbolic link` | the path does not name the saved file | ask the user for the path again |
| `macOS refused the read` | the file is in a privacy-protected folder | have the user allow the terminal access, or move the file into their home folder |
| the token file `holds a carriage return`, `carries no …`, `is not one "<key> <value>" pair` | the file is not the one the page saved, or was edited | the token is treated as exposed: the user revokes its prefix, issues another and saves the new file |
| `disagrees with the token file's …` | a flag you passed differs from the file | run again with only `--token-file` |
| `no gui launchd domain for this user` | macOS over SSH | the macOS install supports a GUI login with Claude Code in a Terminal there |
| `a roaming profile copies %APPDATA% …` | a Windows roaming profile | not supported: the token would leave the machine at logoff |
| selftest `token_accepted` `fail`, or health `HTTP 401` | the site does not accept the token | the user issues a new token and saves its file; run step 3's command with the new path, which replaces it; then they revoke the old prefix |
| arrival `a batch was REFUSED`, with `identity_mismatch` | the token was issued for other names | as the row above, issuing it for exactly the install id and seat id the file carries |
| health `NOT CURRENT` | the checkout and the site run different builds | an older checkout: pull and re-run; newer: the site's operator deploys it |

[`INSTALL.md` § The checks](INSTALL.md#the-checks) reads every code.

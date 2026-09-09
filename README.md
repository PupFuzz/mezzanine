# Mezzanine

**The balcony above the office floor.** A live dashboard for a fleet of coding agents:
every PM, solo, and implementation agent rendered as a character at a desk, showing what
they are actually doing right now — with a drill-down into their tasks and subagents.

> Status: **early build.** The Laravel host exists in [`server/`](server/) — an MFA-gated
> shell with no floor behind it yet, plus the **admin console** at `/admin` (users, agent
> manage/remove, and floors + their maps) and `php artisan mezzanine:user:create`, which is what makes a fresh deploy
> reachable at all — see [The first account](#the-first-account-and-the-way-back-from-a-lockout). The **procedural character generator** exists in
> [`resources/characters/`](resources/characters/) — dependency-free ES modules that draw a
> seat's character from its identity alone; open `tools/characters/harness.html` over a local
> static server to see it. The kanban automation in `.github/workflows/` also runs; see
> [Kanban](#kanban) below.

## What it is

Agents in this fleet coordinate through GitHub threads and kanban boards. That record is
complete but not glanceable: you cannot look at it and see *who is working, who is idle,
and who is stuck.* Mezzanine is that view — an office building where each PM gets a floor,
solo agents share one, and workers sit at desks doing visibly real things.

The guiding rule, borrowed from prior art: **an avatar walking IS the status.** Every motion
that *means* something is driven by a real event — a desk changes because the wire said so, and
nothing on it is canned status theater. The room around the desks may carry a little warmth that
means nothing at all — a lamp's glow, an LED on a rack. `docs/design/FLOOR.md § 6` owns the rule,
the test that tells the two apart, and the bound on the warmth.

## Architecture — three planes

| Plane | What it does | Where it runs |
|---|---|---|
| **Telemetry** | `fleet-reporter`, a Claude Code hook bundle, POSTs turn/tool/session events | every agent machine (Linux + Windows) |
| **Aggregation** | fleet-state store + live feed, merged with coordination and board events | this repo (D-10) |
| **Presentation** | Laravel app serving a Pixi.js office floor over websockets, behind MFA | this repo |

Telemetry is **programmatic end to end** — the harness fires the hooks and the reporter
posts the JSON. No model is asked to describe itself.

## Repo layout

```
server/                     the Laravel host + MFA-gated shell   ← exists
server/resources/js/floor/  Pixi.js office floor (scene, characters, camera)
resources/characters/       the procedural character generator + LINEAGE.md ← exists
resources/floor/            the CC0 tileset + Tiled map (card #7341)
fleet-reporter/             cross-platform hook bundle + installer
docs/                       design notes, feed schema, CHANGELOG, ATTRIBUTION
bin/, tools/                prod deploy (bin/deploy.sh), kanban + design-doc
                            automation, CI gates, harnesses          ← exists
```

The application lives under `server/` and not at the repo root, which already holds this
README, `VERSION`, `bin/`, `docs/` and `tools/`. That path is pinned as a decision
(`docs/PLAN.md` D-16) because the CI lanes, the deploy script and the ingest/store cards all
key on it.

**`resources/` at the repo root is the asset root**, and it is deliberately *outside* `server/`:
every file under it owes a row in `docs/ATTRIBUTION.md`, and `bin/asset-provenance.py` fails
the build when one does not. The generator has no dependency on Laravel, on Pixi, or on
anything else — so it sits beside the app rather than inside it, and a future rebuild of the
presentation layer does not move it. Laravel's own `server/resources/` (views, CSS, app JS) is
not an asset tree and owes no provenance rows.

### Running the server locally

```
cd server
composer install                                    # ← local only; a HOST installs --no-dev (below)
cp .env.example .env && php artisan key:generate    # .env is never committed
php artisan migrate
php artisan mezzanine:user:create                   # ← the first account; nothing else creates one
php artisan test
```

Every page requires a second factor, so a freshly created account is sent to the enrolment
screen and reaches nothing else until it finishes there.

⛔ **A deployed host installs with `composer install --no-dev`**, and the reason is a credential
rather than a few megabytes: `database/factories/` and `database/seeders/` sit in composer's
`autoload-dev` block, so under `--no-dev` neither is loadable at all; install dev dependencies on a
host and both are. `Database\Factories\UserFactory` **used to** hash the literal `password` and now
mints a random value per run, which closes that class in code on every host — the `--no-dev`
obligation stays as defence in depth, because nothing reds if a host never honours it.
`docs/PLAN.md` § 5 carries this and the `CACHE_STORE` obligation beside the others.

### The first account, and the way back from a lockout

**`php artisan mezzanine:user:create` is the only thing that creates a user account.** There is
no self-service registration (`config/fortify.php` says why), no seeder that mints one, and no
first-run web page — so on a fresh deployment *nobody can sign in until this command is run on
the host*, and running it is part of standing the host up, not an optional extra.

```
php artisan mezzanine:user:create                             # prompts for the password
php artisan mezzanine:user:create --name=… --email=… --generate   # mints one, prints it ONCE
```

It takes **no `--password` option, deliberately**: an argument lands in argv, which is
world-readable in `/proc` for the life of the process and is written verbatim into shell history.
Give the password at the prompt (never echoed), or use `--generate` and hand the printed value
over out of band — it is shown once and stored only as a hash.

⛔ **It is also the ONLY way back from a locked-out install**, which is why the console refuses to
retire the last account that can still sign in. Retiring accounts is deliberately not a deletion
— the whole row is kept: who the account was (its name and address), who retired it and why —
and a retired account can no longer authenticate on any path. **A retired account is also no
longer editable**, so its address can never be freed and handed to somebody else; if the person
needs an account again, create one under a different address. If an install somehow reaches a
state with no account that can sign in, there is no
password reset and no registration page: shell access on the host
and this command are the recovery.

### Losing your authenticator

**Two ways back, and the first needs nothing from the host.**

1. **Recovery codes.** Eight are minted when you enrol and they are shown on the enrolment page —
   *write them down there*. Each signs you in once at the two-factor challenge. They are
   re-displayable at `/two-factor/recovery-codes` (linked from the dashboard) behind your password,
   and the same page regenerates them, which invalidates the previous set immediately.
2. **An emailed reset**, at `/two-factor-reset`, linked from the challenge screen. A code goes to
   the address **already on the account** — there is no field that could redirect it — and entering
   it **removes the second factor and signs nobody in**: you then log in with your password and are
   made to enrol a new authenticator before anything is reachable. The code is single-use, expires
   in 30 minutes, and is typed into a form rather than clicked in a link, so it never reaches a web
   server's access log.

⛔ **Path 2 is a deployment dependency, and it is off until an operator satisfies it.** It needs a
real outbound mail transport: `config/mail.php` and `.env.example` both default to
`MAIL_MAILER=log`, which writes the whole message — reset code and all — into `storage/logs/`
instead of sending it. On such a host the reset page says so plainly rather than pretending a code
was sent. **Prove the host can send before somebody needs it to:**

```
php artisan mezzanine:mail:preflight                       # what is configured
php artisan mezzanine:mail:preflight --to=you@example.com   # and whether it actually sends
```

⚠ **An emailed reset makes mailbox possession enough to strip the second factor.** That is inherent
to the mechanism and was accepted deliberately; it is why the destination is the stored address and
never a submitted one, so an attacker must intercept the mail rather than redirect it. An operator
who does not want that property leaves `MAIL_MAILER` unconfigured and the path stays closed.

### The admin console

`/admin`, behind the same session + second factor as the dashboard. It carries **users** (create,
edit, retire), **agents** (read seat state, the `mezzanine:retire` operator act, and the
**retired seats** record — a removed seat's desk goes from the floor immediately, so the console is
where *who retired it, when and why* lives, `card#9078`) and **floors** (each floor's Tiled map: how
many desks the room has and where they sit, `card#9085`). Pinning a named seat to a chosen desk is
deferred to `card#9071` because it would store a fact `docs/design/FLOOR.md § 3.2` derives.

**A floor's map is authored in Tiled and installed through the console** — export it as a JSON map
(`.tmj`) with the tile layer format set to CSV, referencing the tileset by file rather than
embedding its image, and paste it in. The console refuses anything else by name, and shows each
floor's slot count against the seats it renders so a map that is short of desks is visible where it
can be fixed rather than on the floor. Removing a map removes the room and nothing else: the
install, its seats and their state are the fleet's.

**Every account that can reach the console is an operator** — there are no roles, because this
application has one class of user. ▶ **The trigger that reopens that decision, stated so it is a
decision and not drift: the first time an account must exist that may NOT administer other
accounts.** At that point the console's route group needs a real authorization layer;
`server/routes/admin.php` carries the same trigger beside the middleware it would change.

⛔ **There is no "add an agent" and no "delete a user", and both absences are deliberate.** A seat
exists because it *reported* (`docs/design/FLOOR.md § 3.4`); an account stops working by being
retired, and its record survives so that everything it did still resolves.

## Licensing and attribution

MIT (see `LICENSE`). Mezzanine's floor derives from prior open-source work and ships
`docs/ATTRIBUTION.md` naming every upstream. Office tiles are CC0. **No commercially-licensed
assets are vendored here.**

The character generator is a **port** of munder-difflin's (MIT), at a pinned commit:
`resources/characters/LINEAGE.md` records the upstream, the commit, the reproduced MIT notice,
and — the part that makes it a port rather than a fork — what was deliberately not taken and
why. **Its pixel art is interim** — the operator ratified a high-resolution, whimsical, modern
art direction on 2026-08-26/27 (`docs/design/FLOOR.md § 10.4`); what the port bought and keeps
is the **seed machinery**, so a seat's appearance is a pure function of `(install_id, seat_id)`
and looks the same on every browser with nothing stored.

Two CI gates enforce the licence claim rather than leaving it to discipline: **every asset
needs a provenance row** — hash, SPDX from a closed allowlist, and an `origin` of `first-party`
or `licensed` checked against the row's own source URL — and **every asset must be a file that
gate can see**, i.e. a known format, with a path, never bytes pasted inside another file where
it would have no row at all. **What the gates do NOT prove is that a row is true**;
`docs/ATTRIBUTION.md` says so under *What a green gate does not mean*, and review is what
stands there.

## Branch model

`dev` is the **integration branch** — all work lands there. `main` is the **release branch**
and the repo default. Both are protected by rulesets: PR required, no direct pushes, no
force-pushes, no deletion — and each branch permits exactly **one** merge method, so the
release topology is enforced by the repo rather than remembered by whoever clicks the button.

Which method each branch permits, and everything else about versions — the `VERSION` file,
release-PR shape, tagging, the two deploy targets, and the wire-compatibility rule between
`fleet-reporter` and the ingest — is owned by **`docs/VERSIONING.md`** and not restated here.

## Kanban

Work is tracked on a kanban board, and PRs move their cards automatically. Put `card-<id>` in
your branch name and `card#<id>` in your PR title; a PR that names no card is fine. What runs,
what an operator must configure first, and the failure modes that are silent if they skip it:
**`docs/KANBAN.md`**.

# Mezzanine

**The balcony above the office floor.** A live dashboard for a fleet of coding agents:
every PM, solo, and implementation agent rendered as a character at a desk, showing what
they are actually doing right now — with a drill-down into their tasks and subagents.

> Status: **early build.** The Laravel host exists in [`server/`](server/) — an MFA-gated
> shell with no dashboard behind it yet. The **procedural character generator** exists in
> [`resources/characters/`](resources/characters/) — dependency-free ES modules that draw a
> seat's character from its identity alone; open `tools/characters/harness.html` over a local
> static server to see it. The kanban automation in `.github/workflows/` also runs; see
> [Kanban](#kanban) below.

## What it is

Agents in this fleet coordinate through GitHub threads and kanban boards. That record is
complete but not glanceable: you cannot look at it and see *who is working, who is idle,
and who is stuck.* Mezzanine is that view — an office building where each PM gets a floor,
solo agents share one, and workers sit at desks doing visibly real things.

The guiding rule, borrowed from prior art: **an avatar walking IS the status.** Every
motion on the floor is driven by a real event. Nothing is canned status theater.

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
bin/, tools/                kanban + design-doc automation, CI gates, harnesses ← exists
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
composer install
cp .env.example .env && php artisan key:generate    # .env is never committed
php artisan migrate
php artisan test
```

Every page requires a second factor, so a freshly created account is sent to the enrolment
screen and reaches nothing else until it finishes there.

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

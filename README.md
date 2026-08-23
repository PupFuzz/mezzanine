# Mezzanine

**The balcony above the office floor.** A live dashboard for a fleet of coding agents:
every PM, solo, and implementation agent rendered as a character at a desk, showing what
they are actually doing right now — with a drill-down into their tasks and subagents.

> Status: **scaffolding.** No application code yet. The only thing that runs is the kanban
> automation in `.github/workflows/` — see [Kanban](#kanban) below.

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
| **Aggregation** | fleet-state store + live feed, merged with coordination and board events | the webhook bridge |
| **Presentation** | Laravel app serving a Pixi.js office floor over websockets, behind MFA | this repo |

Telemetry is **programmatic end to end** — the harness fires the hooks and the reporter
posts the JSON. No model is asked to describe itself.

## Repo layout (planned)

```
app/, resources/, routes/   Laravel host + MFA-gated shell
resources/js/floor/         Pixi.js office floor (scene, characters, camera)
fleet-reporter/             cross-platform hook bundle + installer
docs/                       design notes, feed schema, ATTRIBUTION
```

## Licensing and attribution

MIT (see `LICENSE`). Mezzanine's floor derives from prior open-source work and ships
`docs/ATTRIBUTION.md` naming every upstream. Character art is generated procedurally in
code; office tiles are CC0. No commercially-licensed assets are vendored here.

## Branch model

`dev` is the **integration branch** — all work lands there. `main` is the **release branch**
and the repo default. Both are protected by rulesets: PR required, no direct pushes, no
force-pushes, no deletion — and each branch permits exactly **one** merge method, so the
release topology is enforced by the repo rather than remembered by whoever clicks the button.

Which method each branch permits, and everything else about versions — the `VERSION` file,
release-PR shape, tagging, the two deploy targets, and the wire-compatibility rule between
`fleet-reporter` and the ingest — is owned by **`VERSIONING.md`** and not restated here.

## Kanban

Work is tracked on a kanban board, and PRs move their cards automatically. Put `card-<id>` in
your branch name and `card#<id>` in your PR title; a PR that names no card is fine. What runs,
what an operator must configure first, and the failure modes that are silent if they skip it:
**`docs/KANBAN.md`**.

# Changelog

Every PR whose title or branch carries a `card#NNNN` token owes a line-initial
`- **card#NNNN** — …` bullet under `## [Unreleased]`, **in the same PR**. A PR that names no
card owes nothing. `docs/PLAN.md § 4` owns that rule and the reasoning behind it, including
why the bullet must be line-initial; `docs/VERSIONING.md` owns when a release collects these
entries and retitles the section.

⛔ **Enforced since 2026-08-30 by `bin/release-pr-guard.py` R4**, on every PR — for seven days
before that it was prose, and six cards' work merged with no entry at all. The two cases that
owe nothing are a PR into `main` (the release retitles this section) and a PR that REMOVES a
card's bullet (a revert of unreleased work); both are argued in that file's docstring. **R5**
holds this file's SIZE under `1 MiB − the bytes it grew in the last 14 days`, so it reds while
there is still time to archive released sections rather than after the contents API has begun
returning it empty. **R6** (card#9707) gives this section a third reader: on a release PR it
compares the cards bulleted here on `dev` against the head's changelog, so a release cannot
quietly ship with fewer cards than `dev` holds. **R7** (card#9814) refuses a release PR whose
copy of this file carries more than two released sections, and so enforces the archiving below.

Sections are newest-first: `[Unreleased]` collects what has landed on `dev` since the last
release, and a release retitles it (`docs/VERSIONING.md § Release flow` step 4).

This file holds `[Unreleased]` and the latest released section only. Every older release is one
file per tag under [`docs/changelog/`](changelog/) — `ls docs/changelog/` lists them — moved there
verbatim by release flow step 13 (`docs/VERSIONING.md`), which R7 enforces. R5 gates this file's
size; `docs/PLAN.md § 4` says why the archive files need no gate of their own beyond R7's backstop.

## [Unreleased]

- **card#11544** — **A seat that wraps its statusLine with the reporter no longer shows the false *the harness payload moved under this reporter* badge at every session start.** The statusLine payload carries no usable `context_window` in the first seconds of every session (D1 § 6.11), and the reporter now counts that gap as `context_window_unavailable`, which raises no badge. It used to count it as `payload_key_missing.context_window`, which raises `harness_contract_moved`, so each new session raised the badge again and its 24 h window never ran out on an active seat. A seat upgraded from an older build clears the badge at its first heartbeat after `fleet-reporter.js` is replaced and its flusher restarted: the new flusher moves the total the older build saved in `state.json` to the new name. Every other `payload_key_missing.*` key still raises `harness_contract_moved`, including a key a later build starts reading, and D1 § 9.3 now says so in place of a *key marked required in § 6* that no section marked. `fleet-reporter/INSTALL-LINUX.md` names the upgrade step for a seat that applied Step 4(b).
- **card#11547** — **A seat that goes offline mid-session and comes back picks its session up again.** When a seat's next event names the session the sweeper closed when the seat crossed `offline`, the server re-opens that session, as it already did for a session the reporter closed on 90 minutes of silence. The returning seat renders *working* on a turn it has started, with its `session` and `open_turn` back on the seat object, and the drill-down lists the session as open; it used to render *unknown* (`session_closed_turn_open`) on every turn with no call open, carry `session: null`, and list the session as ended, until a new session started. Calls closed at offline stay closed, and their late completions apply as before. A session the seat itself ended (`clear`, `logout` and the other reasons it sends) stays closed. `session_reopened` keeps counting the 90-minute silence reopen only; an offline round trip is counted once, at the close, by `offline_quiesced_sessions`. D2 § 4.6 records the rule.
- **card#11527** — **Release card promotion skips a card from another board and moves the rest.** When a released commit names another board's card, `bin/promote-cards-by-token` prints a `not on this board` line for it, counts it in its summary, moves every other card, and exits 0; it used to stop the whole run with exit 2 and move nothing, as on v0.8.0. A card read refused with HTTP 403 counts as another board's only after the same token reads a board-14 card back and a board-14 lookup does not find the id, so a revoked or rotated token, or one that lost board 14's view permission, still stops the run with exit 2. The mover now reads every card before it moves any, so an exit 2 at a card read (5xx, timeout, 401, or an unproven 403) leaves every card where it was. A card on another board that the token can read is now skipped the same way, where it used to fail the run with exit 3. `docs/KANBAN.md` records the rule; the change is declared local edit (3) in the mover's header and pinned by `bin/vendor-pin-check.sh`. A card read or board-14 lookup that answers HTTP 200 without the fields the mover judges by also stops the run with exit 2 and moves nothing: a card with no `board_id`, `workflow_stage_id`, `archived_at` or `deleted_at`, or a lookup with no `data` list. Such a read used to skip the card as another board's or as stage-guarded, or move it as live, and exit 0.
- **card#9527** — **An agent waiting on the operator stays *blocked* until that agent's next status update, however long that takes.** The 60-minute limit is gone from both ends: the server no longer resolves a wait at 60 minutes, and `fleet-reporter` no longer emits `attention.resolved(timeout)`. A seat that goes `stale` or `offline` while waiting renders the link state over the wait and renders *blocked* again when it comes back still waiting; the reporter's 90-minute silence close skips a session that is waiting on a human. The wait ends on the reporter's own resolution, on its session ending, on that session's next prompt, turn end, finished tool or session end, or on any session starting on the seat — which the server records as `seat_activity` / `server_seat_activity` until the reporter's own resolution relabels it. Work in another terminal of the same seat leaves the wait standing, and so do a reporter restart or upgrade and an older reporter's 90-minute silence close of the waiting session; a harness killed while waiting is resolved when the seat's next session starts. The desk's *since …* line dates the wait for as long as it lasts. New counter `attention_long_wait` counts each wait of 60 minutes or more once, when it ends; the counters `attention_ceiling_expired`, `attention_ceiling_overridden` and `left_live_resolved_attention` and the predicates `attention_resolved_by_wire` (server) and `attention_resolved_by_hook` (reporter) are retired. The migration `2026_10_08_000001_retire_the_attention_ceiling` drops `attention_requests.ceiling_at` and `ix_ceiling`, adds `ix_purge (resolved_at)`, appends the two new resolution values, and deletes the retired predicate's `seat_predicates` rows; the seat detail's `attention` object no longer carries `ceiling_at` (D2 § 8.2.3 never published it, so `api_version` is unchanged). ⚠ **A seat still running an earlier `fleet-reporter` keeps clearing its own waits at 60 minutes**, because that reporter emits `attention.resolved(timeout)` and the server applies it; upgrade each seat's reporter to end that, after the server. D1 § 6.2, § 6.13, § 6.14 and § 9.4, D2 § 2.1, § 4.4–§ 4.7, § 5, § 6.4, § 7.2, AT-D2-5 and decisions 3, 19, 20 and 33, and FLOOR's drill-down table record it.
- **card#9418** — **Every seat object says since when the seat has been idle, and the install can declare an idle horizon beside it.** The snapshot and the seat REST response carry `idle_since` on every seat: the server-clock instant the seat last entered `idle`, null on every other state, and a new value each time the seat goes idle again, which a seat delta carries when it changes. Set `MEZZANINE_IDLE_NUDGE_AFTER_S` (seconds, 1 to 86400) in `server/.env` and every seat object also carries `idle_nudge_after_s` with that value; leave it empty and the member is absent. A watcher such as the bridge's idle watchdog reads the two to nudge a seat idle past the horizon; the floor draws neither. The upgrade's migration adds `seat_state.idle_since` and fills it for seats already idle from their last activity receipt; a `MEZZANINE_IDLE_NUDGE_AFTER_S` that is not a whole number from 1 to 86400 is treated as undeclared, so the member is absent, and `GET /api/fleet/health` counts `idle_horizon_malformed` for each snapshot or seat response that withheld it, with a server log line naming the key. `docs/design/FLEET-STATE.md` § 8.2.1 owns both members.
- **card#9491** — **A degraded badge clears one day after its counter last rose.** Every counter-derived badge now means *its counter rose within the last 24 h* (operator ruling 2026-09-14): the reporter's `degraded` members (`lossy`, `epoch_reset`, `batches_rejected` and the rest of D1 § 9.3's twelve) and the server's `epoch_reset`, `seq_gap`, `seq_collision` and `reporter_ahead` (D2 § 7.2). Each counter keeps its running total, on the heartbeat and in the seat detail, so a cleared badge still shows what raised it. The server's badges follow the window from the deploy; the migration adds `seat_counters.last_increased_at` and fills it from `updated_at`, so a badge whose counter last rose more than a day before the upgrade clears at the sweeper's first pass after it. The reporter's badges follow it once `fleet-reporter.js` is replaced and the flusher restarted (`fleet-reporter/INSTALL-LINUX.md`); that build dates every non-zero total as rising at its first heartbeat, so an upgraded seat's badges clear 24 h after the upgrade unless a counter rises again. The drill-down marks each counter-derived badge *its counter rose within the last 24 h* and labels the reporter's counters as running totals, where it used to say *since reporter start*. D1 § 9.3 and D2 § 7.3 no longer say a flusher restart resets the counters: the reporter keeps them in `state.json` across restarts.
- **card#11548** — **A busy seat no longer shows `lossy` for dropping spool lines it already delivered.** When the reporter drops an old spool bucket, by the 8-day residency cap or the 32 MiB size bound, `spool_dropped_events` now counts only the bucket's lines past its delivery cursor in `state.json`, and every line of a bucket with no cursor. It used to count every line, so a seat whose spool held 32 MiB of delivered history raised `lossy` on every drop with nothing lost. Once `fleet-reporter.js` is replaced and the flusher restarted (`fleet-reporter/INSTALL-LINUX.md`), dropping delivered lines adds nothing to the counter, so under card#9491's 24 h window the badge clears a day after the counter last rose; the total a seat has already counted stays in its `state.json`. D1 § 9.3 and § 11.3 record the rule.
- **card#11277** — **The floor ignores Tiled's tile flips.** A tile an author rotates or mirrors in Tiled draws unrotated and unmirrored, in its kind's art from the floor's theme, as every tile already did; `docs/design/FLOOR.md` § 10.6 item 6 now says so. The flip bits are still stripped from each cell's tile id, so a flipped cell names the same tile as an unflipped one.
- **card#11330** — **A seat installed by the runbook no longer shows the false *the harness payload moved under this reporter* badge.** The reporter counts an unset `harness_label` config key as `harness_label_unset`, which raises no badge, where it used to count it as `payload_key_missing.harness_label` and raise `harness_contract_moved` on every correctly installed seat. A seat upgraded from an older build has its saved total moved to the new name at its flusher's next pass, so the badge clears once `fleet-reporter.js` is replaced and the flusher restarted (`fleet-reporter/INSTALL-LINUX.md`). `harness_contract_moved` still fires for a missing harness payload key or a hook-name mismatch (D1 § 6.1, § 9.3).
- **card#11468** — **Each desk's facts sit on one soft plate beside its side table, and the task above the creature is a thought cloud.** The label, currency, lag line, context gauge, badges, flag and *nothing done for* now stack together just above the side table, on one rounded cream plate as tall as the rows the desk carries; the side table stands on the desk's own floor line with its interns in front, and the *+N more* tag sits under it. Rows a desk does not carry take no space, so a new row moves the rows above it up by one line. The badge chips are 104 px wide (they were 108). The task bubble is drawn as a scalloped cloud, joined to the creature by three small circles that grow from just above its head to the cloud; the text, its truncation and where bubbles sit are unchanged. Coordination lines and walks now meet a desk at the desk's own mid-height instead of the box's, below every plate. FLOOR § 5.1, § 10.6, § 12, AT-D3-20 and AT-D3-25 and decision 64 record it.

## [0.8.0] — 2026-10-07

- **card#10493** — **The PR-body check runs the fleet linter re-vendored from coord 0.63.0, which adds the `ai-attribution` rule.** `bin/pr-body-lint.py`, its selftest and its fixtures are upstream's 0.63.0 files under mezzanine's provenance header, and `bin/vendor-pin-check.sh` pins the new bodies. Every PR body is held to the same rules as before (the allowed section set, the scope line, the banned openers, the live-state readings, `Built:`, `**Coordinated in:**` and the `FROM:` / `TO:` rule), and now also to `ai-attribution`: no AI model name, session link or AI co-author trailer. The `pr-body-lint` job in `.github/workflows/card-token-lint.yml` passes the PR title to the linter as upstream's workflow does, and on a PR whose title does not open `release:` it judges the body a second time with no title, which keeps the allowed section set and scope line on mezzanine's change PRs. A PR opened by a bot account is not asked for `Built:` or `**Coordinated in:**`. The job's selftest step runs `bin/pr-body-lint.selftest.py --workflow .github/workflows/card-token-lint.yml`, so the selftest checks this repository's own workflow. Judge a body locally with `python3 bin/pr-body-lint.py --body-file <file>`; with no title it gives the strict verdict.

- **card#11046** — **The house room: every floor is drawn in its theme, the cozy studio the operator chose.** The band's wall, its windows' frames and curtains, the elevator's frame, the clock's case, the floor's oat boards and lamplight, the PM office's oak, the walls, the lit landing at the lift, the bookcases, plants, lamps and armchair, and every desk's chair, desk, monitor frame, lamp and mug and side table with its cushion seats are now drawn as vector art by the house theme (`resources/floor/themes/studio/theme.js`), sharp at every zoom; no state, name or number is drawn by it. The windows are 208 × 80, and two rooms on one floor share its floor colour — the per-room floor tints are gone. The shipped default room is re-laid with the scenery and no rug. The interim Kenney furniture kit, its rows and `resources/floor/LINEAGE.md` are removed, and so are the floor tileset's plank and rug tiles: every tile of `resources/floor/tiles/floor-plane.tsx` now declares a `kind` the theme draws, its image only a marker for Tiled. The state chip's quiet colours — `stale`, `offline`, `disabled` — are re-chosen so they stay told apart over the new floor. If the theme or its registry fails to load, every desk shows its placeholder with every fact, the room keeps a plain floor, and the status strip names what failed. ⚠ **A room map stored before this release** that names the removed kit tileset is refused at its next save and drawn without those tiles until then, and the floor's status strip reports *some art failed to load*, naming the tileset; if that room is placed on a planned floor, every building-layout save that places it is refused, naming the room, until its map is re-saved. One that places the plank or rug tile draws those cells as nothing. The floors page now lists every room whose current map names art that is no longer shipped, so its author can re-save it.
- **card#11046** — **A floor can name the design it is drawn in: the layout's `theme` member.** A floor entry in the console's building layout may carry `"theme": "<name>"`; the build ships one theme, `studio` (`resources/floor/themes/index.js`), and a floor that names none is drawn in it. The console refuses a name the build does not ship, at a save and at a restore, naming the themes it does ship; a layout already stored that names one draws the floor in the house theme under a notice, *floor theme `<name>` is not installed — drawn in the house theme — `<floor>`*. No floor looks different yet: the house theme's art lands with a later change. ⚠ **Rolling back below this change:** an older build refuses a layout whose floors carry `theme`, so the lobby reads *the building layout could not be loaded — HTTP 500* and the console's floors page fails to load as well — so before rolling back, save the layout without any `theme` member.
- **card#11046** — **The desk is re-laid for the new room: the monitor is wider, its text smaller, and the creature sits at a third of the desk.** The monitor's frame is 96 × 46 with a screen of 88 × 31, and its text is drawn in its own 8 px type role, so a status line shows about twice the letters it did; the dimmed screen's text is drawn in a light ink that holds 5.35:1 (it was 2.25:1). The creature sits at a third of the desk's width with its chair behind it at every desk, the side table stands at every desk with its seats showing (a desk whose art failed to load draws its placeholder, with no side table), and the bubble, the thread line and the walks meet the desk at the creature's centre. Every fact on a desk is now painted after the desk's furniture, so no furniture can cover one. The floor design for the room's themes (`docs/design/FLOOR.md` § 10.6) lands with it; the house theme's art follows in the same release.

- **card#11461** — **The unused `concurrently` dev dependency is removed, and with it `shell-quote` 1.9.0 (GHSA-pqg4-j6r4-53mv, critical).** Nothing in the repository ran `concurrently`; `composer dev` runs `php artisan dev`. The deploy's `npm ci` no longer installs either package, and `npm run build` is unchanged.

- **card#11046** — **Every character on the floor is now an original animal or vegetable creature, drawn at full resolution.** The bodies — a fox-ish, bear-ish, rabbit-ish, owl-ish, frog-ish, mole-ish, hedgehog-ish and otter-ish creature, and a radish, turnip, mushroom, pea-pod, carrot, eggplant, pumpkin and potato — each seat's chosen from its identity alone, so it looks the same in every browser with nothing stored; interns are chibi versions at the side table and never wear their seat's body. They are vector drawings, sharp at every zoom: each frame is an SVG document the page shows as an image, replacing the pixel people and the canvas that rasterised them. Every pose still draws the one standing frame, as before; the walk to and from the elevator steps through three frames. The munder-difflin port leaves the tree whole — nothing of it ships — so its lineage file and attribution rows go too, and the asset gate's lineage check now fires only on a port in the character tree (a `licensed` row), not on the tree existing. `docs/design/FLOOR.md` § 10.2 and § 10.4 own the creatures, the frame contract and the measurements; AT-D3-24 (`tools/characters/selftest.mjs`, `painter-probe.mjs`), AT-D3-12's lineage half and AT-D3-19's painter legs hold them.

- **card#11363** — **The deploy self-test's `bash-floor` job fetches bash from the kernel.org mirror when ftp.gnu.org is unreachable.** It tries ftp.gnu.org first, then `mirrors.kernel.org`, and checks whatever it fetched against the same pinned sha256 checksums, so the mirror only carries the bytes. An outage of ftp.gnu.org (2026-10-06) had made this required check fail on every pull request.

- **card#9566** — **A seat that leaves walks to the floor's elevator; one that returns walks back to its desk.** When a desk goes from staffed to an empty chair (`stale` or `offline`), the character walks to the elevator on the back wall, the leaves open, it steps in and the leaves close; when a seat comes back, the leaves open and it walks to its desk and sits. Under reduced motion the chair simply empties or the character is simply present. `docs/design/FLOOR.md § 6.2`'s walk note owns the rules: a walk is drawn only, the rows and the log are written at the apply as before, and anything that touches the seat mid-walk cancels the walk and draws its current state. The two rows used to be keyed on `offline` alone, which predicted a walk out of an already-empty chair when a `stale` seat turned `offline`; they now read § 7.1's staffed and empty sides. Every multi-frame floor effect — the coordination envelope and ring included — now survives the renders that land while it runs instead of being cut by the next one. AT-D3-23 (`ASeatLeavesByTheElevatorAndReturnsByItTest`, `fx-elevator`).

- **card#11331** — **A reporter token carried to a seat by hand has one name, and Step 2 consumes it.**
  When the Mezzanine server is on another host, `fleet-reporter/INSTALL-LINUX.md` Step 2 issues the
  token on that host into a `0600` file holding the token alone, the operator carries it to the seat
  as `~/.config/fleet-reporter/mezzanine-reporter-token`, and the seat writes its config from that
  file and then shreds it on both hosts. Step 2's writer does both hops: `FR_TOKEN_OUT` writes the
  token file from the issue output, and `FR_TOKEN_FILE` writes the config from the carried file,
  refusing a file that is not a regular `0600` file of the seat account's, in a `0700` directory of
  its own, holding exactly one well-formed token. Both issuing pipelines refuse to run without `node`
  on `PATH`, so a token is never issued into a pipe nothing reads, and the clean-up shreds only a
  regular file, removing a symlink alone and leaving its target for the operator. This replaces typing the token into the config with an editor (raised on
  PupFuzz/agent-roundtable#597).

- **card#9416** — **An operator opens an agent's console from its desk.** The drill-down shows
  **Open console on claude.ai**, opening in a new tab, to operators only. `fleet-reporter` reads the
  session's console address from the tail of its transcript (at most 1 MiB back) and sends it as
  `console_url` on every `turn.start` (D1 § 6.3), `null` when the bridge has ended or the seat's
  `descriptors` key is not `full`; a value of any other shape is dropped and counted
  `console_url_malformed`. The fold stores only a value matching D1's pattern, in the new
  `sessions.console_url` column, and counts `console_url_refused` for the rest. The seat detail
  response carries `detail.console_url` for a signed-in operator only — the member is absent for an
  observer and for a machine token, and no seat object, snapshot or stream message carries it.
  Seats send the link once their reporter is re-copied (`fleet-reporter/INSTALL-LINUX.md` Step 1).

- **card#9446** — **Every time shows in the viewer's browser timezone.** The floor, the lobby and the
  console show each instant in the zone the viewer's own browser reports, through one converter
  (`public/js/wire/clock.js`). The floor's header now agrees with itself: the sweep and ingest stamps sit in
  the same zone as the wall clock. The floor and the lobby say the zone once, as *times: your local time
  (GMT+5:30)*. Console pages print each time as `YYYY-MM-DD HH:MM:SS GMT±H[:MM]` (`UTC` at a zero offset); with
  JavaScript off they print labelled UTC. Storage and the wire stay UTC, and there is no per-user zone setting. The seat
  retirement message no longer carries a time; the retired-seats record below it does.
  `EveryTimeGoesThroughTheConverterTest` reds when the client, a view or a controller prints a time in one
  of the raw-print shapes it names.

- **card#9415** — **An account is an observer or an operator.** An observer signs in and sees the floor,
  the lobby, each desk's detail and the fleet REST endpoints; an operator also opens the admin console,
  which now refuses an observer with a `403`, and the dashboard links it for operators only. Every
  account that existed before this change is an operator. The console's users module shows and edits
  the role, and a new account is an observer unless its creator picks operator.
  `mezzanine:user:create` takes `--role` (default `observer`) and refuses to create an observer on an
  install with no active operator; the new `mezzanine:user:role --email=… --role=…` changes an
  existing account's role and is the CLI way back from a lockout. The console and both commands
  refuse to demote the last active operator, and retirement now refuses the last active operator
  rather than the last active account, so an install cannot be left with only observers. This replaces card#9070's D3, whose trigger fired
  on 2026-09-13 (`docs/PLAN.md § 0`).

- **card#11314** — **`web-auth/cose-lib` moves from 4.6.0 to 4.7.3, past three advisories.** The lockfile
  update clears GHSA-9v8c-2mgr-qvx3, GHSA-h7p4-6f74-7w4g and GHSA-rh56-4rc8-hj58 (each patched in 4.7.2). The
  library arrives through Fortify's passkey support, which stays disabled.

- **card#11292** — **The reporter removes secrets and host names from tool descriptors, and a seat can choose
  to send less.** The descriptor sanitizer (D1 § 7.3) now replaces the value of any assignment or flag whose
  name contains `pass`, `pwd`, `pw`, `secret`, `token`, `key`, `auth`, `credential` or `cookie` (`PGPASSWORD=`,
  `MYSQL_PWD=`, `SECRET_KEY=`, `--db-pass`), the value of `Authorization:`, `Proxy-Authorization:`, `Cookie:`
  and `Set-Cookie:` headers, every `name=` value of `vault write` / `vault kv put|patch`, JWTs, the
  `rk_live_`/`rk_test_`/`whsec_`/`hvs.`/`hvb.`/`ya29.`/`dop_v1_`/`shpat_`/`npm_`/`SG.`/`pypi-` prefixes, long
  mixed-case base64url tokens, the value of a quoted JSON or dict key such as `{"password":"…"}`, IPv6
  literals, and host names (a URL's host, including after a password-less user, and dotted names under a
  curated TLD set) with `‹redacted:host›`. A `WebFetch` descriptor
  therefore shows the scheme only. Free text (dispatch descriptions, `Grep` patterns, `WebSearch` queries,
  commit messages) passes through every rule. A new optional config key, `descriptors`, takes `"full"`
  (the default), `"paths"` (file paths for `Read`/`Write`/`Edit`/`Glob` only) or `"none"` (tool names and
  timing only); `"paths"` and `"none"` also drop the subagent title, and any other value is a config error.
  Before the change, all of these shapes passed whole, as a pre-install audit by another seat found. An installed seat takes the change by re-copying the artifact
  (`INSTALL-LINUX.md` Step 1).
- **card#11144** — **The PM office has walls, drawn from above.** The floor now follows one projection, a 3/4
  top-down oblique (`docs/design/FLOOR.md` § 10.4's new projection bullet, the operator's ruling of
  2026-10-03): the back wall shows its face and every other wall shows only its top edge, a strip one map
  cell wide in the wall colour. The shipped room draws its two side edges and an office around the reserved
  desk — a partition, a front return and a 96 px doorway facing the room — as a `walls` tile layer of the new
  first-party wall-strip tile (`resources/floor/tiles/floor-plane/wall-strip.svg`, tile id 2 of
  `floor-plane.tsx`), placed by script from the reserved desk's box, every cell outside every desk slot. The
  back wall's band gains an end post up each end, so the corner where a side wall meets it closes (the
  operator's ruling of 2026-10-04, option B). An authored room keeps its own look until its author paints the
  tile. `tools/design/verify-floor.py` G-walls holds the shipped room's walls outside every slot, keeps the
  kit's elevation-only tiles off it, and reds when the tile's fill and `--house-trim` differ;
  `TheFloorDrawsItsFrameTest` holds the end posts inside the band and clear of every grid and the clock.
  `design-11144-design.md`'s walls question is superseded by this rule.

- **card#11289** — **A desk shows the board card its seat is working on.** `php artisan mezzanine:board-poll`
  runs every five minutes from the scheduler, reads each board in `BOARD_IDS` over HTTPS with the read-scoped
  `BOARD_API_TOKEN`, and writes, for every seat mapped to a board user, the most recently updated card assigned
  to that user (greatest `id` on a tie) into `seat_board_task` — or a row saying the board has no card for it.
  The fold and the sweeper render that card as the desk's task title with `task.source: "board_card"` and
  `task.ref: "card#N"`, and fall through to the telemetry title with `task.degraded: true` once a row is 30
  minutes old. A poll that fails on any page of any board writes nothing, counts `board_poll_failed`, logs a
  failure class with a status and a userinfo-redacted URL, and exits non-zero; a clean poll counts
  `board_poll_ok`; with `BOARD_IDS` empty the job does nothing. `php artisan mezzanine:seat-board-user
  --seat=<install>/<seat> --board-user=<id> | --clear` sets or clears a seat's board user and deletes its
  board row in the same transaction, refuses a board user another seat holds, and refuses a retired seat.
  Both counters are on `GET /api/fleet/health`. The three keys are in `server/.env.example`, empty.
  `docs/design/BOARD-TASK.md` § 0, § 5, § 12, § 13 and § 14, `docs/PLAN.md` D4, D2 § 4.9, § 13 and § 14,
  D3 § 1.2 and the README record the build.
- **card#11144** — **The PM sits at the desk reserved for it.** `assignSlots()` in
  `server/public/js/floor/floor-layout.js` seats a room's one seat whose relayed `protocol_agent_role` equals
  the map's `reserved_for` at the reserved desk, and takes that desk before the probe loop whether or not
  anyone holds it, so every other seat hashes over all of the room's `S` desks, as before, and probes past it. With nobody relaying
  the role the desk stays empty and the floor reads *reserved for `pm` (id 3) — no seat holds that role*; with
  two or more, neither sits there and § 9 F22's notice names them (the operator's rulings Q3 A and Q4 A). On
  the shipped default the PM sits at the back-row right corner, desk id 3, and a seat relaying any other role,
  or none, sits where it hashes with the corner empty. A delta that changes which seat the desk seats is
  animation A16, its cause that delta's `state_version`. `docs/design/FLOOR.md` § 3.2–§ 3.5, § 5.5, § 6.2,
  § 9 F22, § 11 (AT-D3-1, AT-D3-3, the new AT-D3-22, the `fx-collision` and `fx-office` rows), § 12, § 13
  decision 48 and Appendix B row 17 are re-worked over the reservation; `tools/design/verify-floor.py` G8
  re-derives § 3.2's and § 3.3's worked tables over it with a control, and `tools/design/floor-fixture.browser.mjs`
  draws a fixture run on the real floor page in headless Chromium, with a selftest.
- **card#11252** — **A desk inside a moved Tiled group sits where Tiled shows it.** A room map's desk slot is at
  its object's own `x`/`y` plus the summed `offsetx`/`offsety` of the `desks` layer and every group above it, on
  the floor page and in the console alike, so a group an author drags carries its desks with its furniture. The
  console judges *wholly inside the grid* and two overlapping slots at that position, names the shifted spans in
  its refusals, and refuses an `offsetx` or `offsety` on the desks' path that is not a
  number. Before, the room's tiles moved with the group and its desks stayed where they were. The server sums
  the offsets in `App\Floor\FloorMap`'s one walk of the layer tree, and the client in `mapLayers()`, the walk
  its tiles already read. A map with no offsets places every desk exactly as before.
- **card#11263** — **The ingest refuses IDs that end in a line break.** `POST /api/ingest/events` answers
  `422 invalid_batch` for a `batch_id` or `seq_epoch`, and `422 invalid_event` for an `event_id`, `kind` or
  `session_id`, that ends in a line break, and stores nothing from that batch. Before, such a value passed its
  pattern, and a `session_id` such as `"abc-sess\n"` was stored verbatim as a session no other event could
  match. The anchoring card#11253 fixed for install and seat IDs moved from `App\Support\Slug::pattern()` to
  `App\Support\Anchored::pattern()`, and the patterns in `App\Ingest\Wire` now match through it too. The
  ingest and the read endpoints now share one `Authorization: Bearer` parse, `App\Ingest\TokenResolver::bearer()`,
  and a header whose token is followed by a line break is unauthenticated on both.
- **card#11253** — **Install and seat IDs that end in a line break are refused.** `mezzanine:ingest-token:issue`
  refuses an `install_id` or `seat_id` such as `"aimla\n"` before it writes an install, a seat or a token, and
  its refusal now prints the refused ID as a JSON string, so a trailing line break shows as `\n`. Before, the
  command accepted such an ID and issued a token bound to an ID no reporter sends. `App\Support\Slug::pattern()`,
  which the command and `FloorMap`'s `reserved_for` check both use, now ends the match at the end of the value,
  and `FloorMap`'s own copy of that rule is gone. The building surface's room-map route already answered `404`
  for such an install ID, and a test now holds it to that.
- **card#11144** — **The floor reads which desk a room map reserves.** `mapDesks()` in
  `server/public/js/floor/floor-layout.js` now returns each desk with its `reserved_for` role, or `null` when
  the desk is not reserved, read as the console accepts the property: a Tiled `string`, or a property with no
  `type`. It reads the `desks` layer through the same `mapLayers()` walk as before, so a reservation inside a
  grouped `desks` layer is read too, and the reserved desk sits at its index after the `id` sort. `docs/design/FLOOR.md`
  § 10.3's `desks` row says where the client reads it, and `TheFloorReadsWhichDeskAMapReservesTest` holds
  the client's answer to the server's `FloorMap` over a reserved map written out of `id` order, an
  unreserved map, a grouped `desks` layer and an untyped property, each with a planted defect it reds on.
- **card#11218** — **The three quiet-state chips stay apart as drawn.** The state chip's `stale`, `offline` and
  `disabled` fills are now a cold family against the warm planks — frost `#b8d1d1`, dusk `#5398c3`, slate
  `#8188a4` — replacing `#b0aa9c`, `#828a9a` and `#93a3b3`, which collapsed toward `idle`, `working` and each
  other once a desk's dimmed or dark lighting washed them over the plank, most of all to a colour-blind
  viewer. Each still holds the chip's word at 4.5:1 or better at full light. `tools/design/state-chip-colours.py`
  reads the tokens from the sheet and prints each fill's contrast and every pair's CIEDE2000 distance as the
  tokens, as the hollow chip's edge and as drawn over either plank course, in normal vision and the three
  dichromacies; its `--selftest` plants its own defects. `TheFloorDrawsItsFrameTest` now also reds on any
  `--state-<member>` that holds `--state-ink` under 4.5:1. CI runs `state-chip-colours.py --selftest --check`
  on every pull request, and `--check` reds, naming each pair, when any pair involving `stale`, `offline` or
  `disabled` falls under the tool's bound on the sheet. FLOOR.md § 7.3 now gives `offline` desks the dark
  treatment the floor draws, apart from `stale`'s dimmed one.

- **card#11144** — **Each seat carries its agent's roster role.** On the roster read the protocol agent
  name check already makes, `fleet-reporter` now relays the `role` of the coordination roster entry the
  declared name selects, verbatim, as `protocol_agent_role` on every heartbeat. It relays `null` when the
  name check is not `checked`, when the entry carries no slug-shaped `role`, or when two roster entries
  share the declared name; `selftest`'s `detail.protocol_agent_name_in_roster.roster_role` says which,
  and no check, counter or badge is added. The ingest accepts the member (≤ 48 B, optional, so an older
  reporter's heartbeat is still valid), the fold stores it in a new nullable `seat_state` column, and the
  seat object and its `seat.delta` carry it; `mezzanine:rebuild` resets it with the name pair. A seat box
  shows its role once its reporter is replaced with this build and its flusher restarted.
  `docs/design/EVENT-SCHEMA.md` § 3.1, § 6.14, § 18.13 row 6 and AT-27, `docs/design/FLEET-STATE.md`
  § 6.4, § 6.5, § 8.2.1 and its re-measured sizes, and `docs/design/FLOOR.md` § 5.6 follow; the subagent
  cap's arithmetic in FLOOR.md § 8.1 is re-measured on the larger worst-case delta (the cap stays 8).

- **card#11144** — **A room map can reserve one desk for a role, and the shipped room reserves its
  back-row right corner for the PM.** A `desks` object may now carry one property, `reserved_for` — a
  Tiled `string` naming a role in the protocol agent name's shape (lowercase `[a-z0-9-]`, at most 48
  bytes), such as `pm` — and at most one desk in a room may carry it. The console refuses, by the desk's
  Tiled id, any other property on a desk, a `reserved_for` that is not a string role name (a property with no
  `type` is a string, as Tiled documents) or appears twice on one desk, a reserved desk with no `id` or with
  an `id` another desk also declares, and a second reserved desk, at a save and at a restore alike; a reserved desk is still held
  to the furniture box. The revisions page lists each revision's reserved desk beside its slot count, and a
  save's result names the reserved desk before and after. `resources/floor/default.tmj` reserves `id 3` for
  `pm`, and the floor seats the room's one seat relaying `pm` there, or nobody.
  `tools/design/verify-floor.py` G8 holds the default's reserved desk equal to `FLOOR.md § 10.3`'s sentence
  and § 12's new Measured row. This replaces card#9071's allowlist of none for a desk object's properties.
- **card#11187** — **A room map whose desks sit inside a Tiled group is seated.** The console already
  accepted a map with its `desks` object layer inside a `group` layer and counted its desks; the floor
  now reads that layer at any depth too, so such a room places its seats at the map's desks instead of sending
  every seat to the overflow row under *floor map is short N desks*. The floor client walks a map's
  layer tree in one place, `mapLayers()` in `server/public/js/floor/floor-layout.js`, which the desk
  slots and the tile drawing both read. `docs/design/FLOOR.md` § 10.3's `desks` row says so, and
  `ADeskLayerInsideAGroupIsSeatedTest` holds the floor's `S` to the server's for a grouped map and the
  tile walk to a group's offset, opacity and visibility.

- **card#11058** — **Interns are drawn as sprites, each keyed by its own call.** Each open subagent at a
  desk's side table is now drawn as a small character of its own — static, 20 × 32, clipped to its rect —
  from the same character tree the seats use, under the key `seat~<call_id>` (the operator's ruling of
  2026-10-02, Q3), so an intern keeps its look when the list reorders, a sibling leaves or the page
  reloads. An untitled intern is drawn inside a dashed edge, and its fallback glyph is dashed too. An
  intern whose art fails to load falls back to the small glyph in its own rect, that intern alone: the
  seat keeps its art, the other interns keep theirs, and the status strip reads *some art failed to load*.
  The page forgets an intern's sprite once it is no longer drawn (the character tree gains `forget()`; its
  hash is updated in `docs/ATTRIBUTION.md`), so a floor left open holds only the interns on screen.
  `painter-probe.mjs` holds each intern node to its element (its viewport, its class, the tree key it was
  drawn under, static), holds the badge and flag text classes, and paints every planted seat of two or
  more interns in both orders on fresh painters to show each intern keeps its sprite; AT-D3-19 gains the
  per-stool leg and AT-D3-20 (e) the stool rects. FLOOR.md § 5.4, § 8, § 9 F14, § 10.4 (the key, and the
  interns' collision figure stated as an estimate), § 13 (decision 47), AT-D3-19 and AT-D3-20 record it.

- **card#11058** — **The desk's look: the nameplate in its own type, the state in its colour, and the art
  held in its rect.** The nameplate is drawn at 13 px bold — the desk's second measured type role, after
  the operator's ruling of 2026-10-02 — and measured, cut and centred in that role, so at fit on a
  1,280 × 800 window it reads at about 10.6 CSS px beside the facts' 8.1. The state chip is filled with
  its state's colour (hollow and edged in it for a seat the floor cannot confirm, hollow red for an
  unrecognised one), the flag **⚠ +N** is drawn as a chip of its own, and the plate has its own paper;
  the colours are new tokens on `server/public/css/mezzanine.css` (`--state-*`, `--scene-plate*`,
  `--scene-flag*`). The character and the desk are drawn inside clipping viewports at their rects, so no
  art reaches past the rect it is given. `painter-probe.mjs` now holds every node the painter draws for a
  desk equal to its layout element, not only inside the desk's box, and
  `ThePageChromeIsOneLinkedStylesheetTest` reads the painter's style as built, so a token in a generated
  rule is checked too. FLOOR.md § 5.1, § 10.4, § 12, § 13 (decision 46) and AT-D3-20 record it.

- **card#11058** — **The desk reads at a glance: it draws the ruled set, and every other fact is in the
  drill-down and the desk list.** Following the operator's ruling of 2026-10-02, each desk draws its
  character (or the empty chair), the nameplate on a plate, a state chip with the state's word, the
  label line, the currency label, the lag line, the context bar and its percentage, a badge row of two
  (`config_invalid` and `fold_lag` first, then recognised badges in the wire's order), one flag
  **⚠ +N** for every other unusual item, the interns, the quiet age, the hatch, the dimming and the task
  bubble. No raw unrecognised string is drawn on the desk: the chip and the label line read
  *unrecognised*, *unknown — unrecognised reason* or *API error — unrecognised*, and the currency label
  *was: unrecognised (…)*; the raw values are in the drill-down and on the desk list. The action's start
  and running time, the open-call count, the model label, the last event, the gauge's numerals and
  source, the oldest-badge line, *sending nothing* and the interns' labels move off the desk to the
  drill-down and the list. The desk list's second line now says whether the desk is *moving* or
  *still*. The art is drawn in proportion (`xMidYMax meet`) and the art column is wider, so the
  character stands at three times its pixel size. `TheNewDeskKeepsEveryLeafTest` holds every fact the
  desk drew before this change to the drill-down and the list, and holds the desk to exactly the ruled
  set, against a committed record of the desk as it was. FLOOR.md § 5.1 (*the glance set*), § 5.4,
  § 7.1–§ 7.4, § 7.6, § 8, § 9, § 10.3, § 11, § 12 and § 13 record it.

- **card#11058** — **The drill-down panel carries every fact the desk draws.** Opening a desk now shows
  the desk's own render on one line (its glyph, pose, lighting, whether it is moving or still, and
  *unconfirmed* for a seat the floor can no longer confirm), *sending nothing* on a `config_invalid` seat,
  *N open calls* when more than one call is open, the monitor's light with *a subagent's call* when the
  monitor shows one, every unrecognised value as its raw `field: value` line under *unrecognised*, and
  each badge's id as the opening text of its row (an unrecognised badge's row reads its id, then
  *unrecognised*). Every one is read off the seat object, so each draws when the seat detail could not be
  read. This is the first step of the desk redesign: the panel holds these facts before the desk narrows
  to the glance set the operator ruled on 2026-10-02, and the desk itself is unchanged here. FLOOR.md
  § 4.3, § 5.4, § 9 F9 and AT-D3-11 record it. `DrillDownRendersTheSeatTest` holds each new slot with a
  control that removes its write, and `TheDrillDownDrawsTheDesksOwnMotionTest` holds the panel's *moving*
  / *still* to the motion of the desk the same frame draws, under both reduced-motion readings and on a
  floor stilled by a refused session.

- **card#11045** — **A Safari trackpad pinch zooms the floor and the lobby.** Safari reports a trackpad
  pinch as its own gesture events, which the camera now reads directly: the pinch zooms the drawing about
  the pointer, on the floor and in the lobby, through the same camera wire as every other gesture, and the
  gesture is kept from the page. Before, those events were left to the browser, and only the Ctrl+wheel
  that Safari 15 and later send after them reached the camera. One pinch zooms once: taking Safari's gesture
  events keeps it from sending that Ctrl+wheel, and on an iPhone or iPad a two-finger pinch zooms by its
  touches only. Over a lobby with no building
  drawn the pinch stays the browser's, as the wheel does, and during a lobby ride it is taken as the wheel
  is. FLOOR.md § 4.5 (and its ride-hold bullet) and § 13 row 39 record it. The new Safari steps in
  `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest` fail on the previous code. A real Mac trackpad
  and a real iPhone or iPad were not exercised; synthetic gesture events in a headless browser were.

- **card#11045** — **The shipped default floor map is a six-desk office that reads at fit in a laptop
  window.** Operator rulings of 2026-10-01. Every room with no authored map now renders two rows of three
  desks on a plank floor, with a narrow strip of scenery at each side — a bookcase and two plants on the
  left, a floor lamp, a plant and a bin on the right — and a rug between the rows; there is no conference
  room or lounge, and a desk slot no seat holds is plain floor. The map paints no wall of its own, so the
  floor's tall back wall is the room's only wall. At 1,280 × 800 the floor page fits the whole room at
  0.81 and draws the desks' 10 px text at 8.1 px, where the twelve-desk default drew it at 4.2 px. The
  default now declares 6 desk slots instead of 12: a seventh seat in an unauthored room sits on the
  overflow bench under the *floor map is short N desks* notice until an operator authors a bigger map in
  the console, and a fifth seat in a four-seat room is more likely to land on a held slot and move one
  desk. A lamp's glow is drawn inside the lamp's own tile, at its head, instead of twice its size around
  its foot, so a lamp at a room's edge no longer glows below the floor. The default's grid is 1,576 × 544
  px (it was 3,024 × 496): on a planned floor, a room placed 496 to 543 px below an unauthored room and
  within its width overlaps it after the upgrade, the floor names both under its overlap notice, and one
  layout save that moves a room clears it. FLOOR.md § 3.2, § 3.3, § 4.6, § 6.3, § 10.3, § 12 and
  AT-D3-3 record it; `tools/design/verify-floor.py` G8,
  `Tests\Feature\Floor\IdentityIsStableAcrossARestartTest`, the new page-surface run of
  `TheCameraMovesTheViewerAndNeverTheFleetTest`, the glow check in `TheSceneDrawsOnlyWhatTheSetLoggedTest`
  and the page-surface check in `tools/design/floor-chrome.browser.mjs` each fail on the previous state.

- **card#11045** — **The floor's room now stands in an office: a tall back wall with a two-door elevator, a
  clock and wide windows, and each room on its own toned floor.** Operator rulings of 2026-10-01. The back
  wall over the floor is taller (160 px of the scene, about 2.2 m at the floor's scale) and holds, at its
  left, a two-door elevator drawn in the lobby cab's form and door colours and the wall clock, with tall,
  wide windows — two mullions, a transom and a sill — along the rest of the wall. The elevator is scenery:
  it never opens, and the floor's *whole building* control is still the way to the lobby's elevator. No
  window is ever laid over the clock: a wall too narrow for one past the elevator and the clock draws
  none, and a wall narrower than those two is widened to hold them. Each room's floor is filled under its
  own map in one of four tones — oak, walnut, sage or slate — chosen from the room's id, so it never
  changes and agrees in every browser; the wall keeps one house colour on every floor. A slab runs under
  the rooms. Nothing the frame draws covers a map author's grid. Slots no seat holds stay plain floor.
  Seats past a map's desks now wrap onto further bench rows at the floor's width instead of running past
  it. A touch whose pointer capture the browser revokes without a release no longer leaves a press held
  that turns the next touch into a pinch. FLOOR.md § 4.2 (the frame), § 9 F13, § 10.4, § 12's band,
  zone and window rows, § 13 rows 40–41 and AT-D3-20's clock clause record it;
  `Tests\Feature\Floor\TheFloorDrawsItsFrameTest` and the new lost-capture steps in
  `TheCameraWireIsOneForBothPagesTest` fail on the previous code. The lobby is unchanged.

- **card#11045** — **The wheel now pans the floor and the lobby; Ctrl+wheel and a pinch zoom.** Operator
  rulings of 2026-10-01. A plain mouse wheel or a trackpad's two-finger scroll pans the drawing in any
  direction, where it used to zoom; Ctrl+wheel and a trackpad's pinch zoom about the pointer; on a touch
  screen two fingers pinch to zoom about their midpoint and one finger drags to pan. When the drawing can
  pan no further the wheel's way, the wheel scrolls the page on, so the sections below the drawing are
  always one scroll away. A one-line hint under each drawing names the gestures (*scroll to pan ·
  ctrl+scroll or pinch to zoom · drag to pan · arrows · + −*, shortened on a phone), shown while the
  camera frames something. Both pages share the change through one camera wire, and a ride in the lobby
  still always arrives: a wheel or a pinch during its glide cuts it to the floor. FLOOR.md § 4.2, § 4.5,
  AT-D3-21, § 13 row 39 and Appendix B rows 15–16 record it. The rewritten checks in
  `Tests\Feature\Floor\TheCameraMovesTheViewerAndNeverTheFleetTest`,
  `TheBuildingCameraMovesTheViewerAndNeverTheFleetTest` and `TheCameraWireIsOneForBothPagesTest` fail on
  the previous code. Real trackpads, real touch screens and Safari were not exercised; Safari may deliver a
  trackpad pinch as events this change does not read.

- **card#11045** — **Every page now has a stylesheet: a dark page chrome around the warm room.** The app
  linked no CSS until now, so every page rendered in the browser's default black-on-white. One plain
  stylesheet, `server/public/css/mezzanine.css`, is linked from the shared layout with a `?v=` version
  taken from the file's own modification time, so a deploy that changes it reaches browsers without a
  cache flush; there is no build step. Every page keeps its title as the header's heading. On the floor
  page the header carries the building's seat counts and the way back to the lobby; the status strip is
  one row of chips in groups (this page's connection, the fleet, the room's clock and sky); notices
  are amber bars under it; the floor's name and the camera's controls sit on a row above the drawing, never
  over it. The drawing's height is now the window's height less the chrome shown above it, where it was
  the whole window's, and the camera fits the drawing's own box — re-measured whenever a banner, a
  statement or a notice appears or goes — so a pointer lands where it points at every window size. The
  desk list, the overflow, the coordination threads and the event log are each a collapsible section,
  closed when the page opens; the drill-down opens as a card over the floor. The floor's drawing takes
  its colours from the same stylesheet, so the palette has one home. FLOOR.md § 4.2 (the chrome, top to
  bottom), § 4.5 (the list view's section), § 10.4 (the palette's home) and § 12's reference-viewport
  row (which surface its figure is measured on) record it.
  `Tests\Feature\ThePageChromeIsOneLinkedStylesheetTest` and new checks in
  `Tests\Feature\Floor\FloorPageWiringTest` fail on the previous code.

- **card#7341** — **The office floor page draws its room at every window size.** Until now a window smaller
  than 1,280 × 800 CSS px got no drawing at all: the page replaced the room with a text list of the desks.
  The operator ruled on 2026-10-01 that every screen displays at any browser size, so the room is now drawn
  in a window of any size, at fit on entry, and the viewer pans it (drag, arrow keys) and zooms it (wheel,
  trackpad pinch, `+`/`-`, the *Zoom in* and *Zoom out* buttons), with *Fit the floor* bringing the whole
  floor back into view. The desk list stays on the page, below the drawing, at every window size — so on a
  large screen it now appears below the room as well — and it carries every desk fact in full where the
  drawing shortens a string to fit its desk. FLOOR.md § 4.5, § 12's reference-viewport row (renamed from
  *Floor viewport floor*), § 13 row 14 and Appendix B row 15 record the ruling.
  `Tests\Feature\Floor\TheCameraMovesTheViewerAndNeverTheFleetTest` enters the floor in a 390 × 700 and
  a 1,280 × 240 window and requires the room drawn, panned and zoomed there; it fails on the previous code.

## [0.7.0] — 2026-10-01

- **`laravel/framework` moves from 13.26.1 to 13.34.0** (#253), past Dependabot alert #1, an XSS in the
  framework's debug page. The debug page is served only while debug mode is on, and `bin/deploy.sh`
  refuses a `server/.env` whose `APP_DEBUG` is not `false`. From 13.31 the framework also checks a
  remember-me cookie against the user's current password hash, so the cookie stops working when that
  hash changes: after a password change, and after the automatic re-hash at the user's next password
  login once `BCRYPT_ROUNDS` has changed. That signs the user's other remembered devices out. Sessions
  and cookies issued before the upgrade stay signed in.
- **card#7341** — **The floor page draws its room tiles again.** Since the room drawing landed (Appendix
  B row 14, slice A, in 0.6.0), the floor page loaded neither tileset: the page's fetch
  (`server/public/js/wire/live-page.js`) handed its consumers a response with `json()` and no `text()`,
  and the tileset loader reads a tileset as text, so both tilesets failed with *response.text is not a
  function*, no tile of the room was drawn and every desk showed F14's placeholder with *some art
  failed to load* on the status strip — contrary to 0.6.0's entry for that slice. The page's response
  now carries `text()`, which asks for a render once it is read, as `json()` does. The probes had
  passed because their fake fetch (`server/tests/Feature/Support/scripted-fetch.mjs`) gave its
  response a `text()` the page's lacked; `Tests\Feature\Floor\TheHarnessFetchIsNoWiderThanThePagesTest`
  now reds when the fake's response and the page's differ in any member, and drives the tileset
  loader over the page's own fetch against a real `Response`, each check watched to fail on the
  unfixed wrapper.
- **`bin/change-pr-body.py` no longer writes AI attribution into a PR body.** The generated body
  ends on its `Built:` and `**Coordinated in:**` lines: the `Generated with Claude Code` model line
  and the session link are gone, and so are the `--agent` and `--session-url` options that filled
  them — a script still passing either now stops with a usage error. The operator's standing
  instruction of 2026-09-27 keeps AI attribution off every GitHub-bound text, and the linter in
  coord 0.58.0 reds it. `bin/change-pr-body.selftest.py` plants those lines back and shows that
  linter reds each one.
- **card#7343** — **The lobby's elevator ride now arrives at the floor, and the lobby has the floor's
  camera at building scale** (FLOOR.md Appendix B row 16, slice A). The lobby opens with every floor plate
  in view; the wheel zooms and a drag pans the building, and a *Whole building* control brings every plate
  back into view. The building takes keyboard focus like the floor's drawing: `+` and `-` zoom, the arrow
  keys pan, and *Zoom in* and *Zoom out* buttons sit beside *Whole building* — which is also how a touch
  screen zooms it. Tabbing to a floor plate that is out of view brings the camera to it. *Ride the
  elevator* moves the cab to the next floor, zooms the camera to that floor's plate and then opens
  `/floor/{key}` — the floor's key, also on a floor the operator has labelled, so the link does not change
  when a label does. The click commits the ride: the ride control is disabled until the camera has reached
  the floor, and a wheel, a key, a zoom button, a drag, a window resize or the *Whole building* control
  during the ride takes the viewer straight to the floor instead of stopping it halfway; tabbing to a
  plate during the ride leaves it gliding, and clicking a floor plate while the ride is gliding — or
  pressing Enter on one — does not open that floor, so a ride whose glide is under way arrives where it
  was going. Once the page has asked for the floor the controls work again, so a navigation the browser
  cancels does not leave the lobby stuck; coming back with the browser's Back button works too. Under
  `prefers-reduced-motion` every camera move cuts rather than glides. None of it is fleet state: no ride,
  zoom or pan writes an animation-log row, and a render leaves the camera where the viewer put it. Each
  floor's name and status line are now readable with the whole building in view, and a floor's link
  announces its name together with its seat summary, e.g. "Floor 2, 2 working · 1 idle" — clicking the
  seat summary also opens that floor. FLOOR.md § 4.1 states what the label does. With no floor layout loaded,
  the lobby's list of rooms flows in the page as it did before, rather than in the building's fixed-height
  drawing, and the mouse wheel, the arrow keys, text selection and a press on a room's link all work on it
  as they did before, with the zoom buttons and *Whole building* hidden and the building out of the tab
  order: on either page the camera takes a wheel, a key, a press or a drag only while it frames something,
  and otherwise leaves the event to the browser and offers none of its controls — on the floor too, where
  *Fit the floor* is hidden and the drawing is out of the tab order until the camera frames the floor. A
  press on a framed drawing starts no native drag and selects no text, and a drag that is under way when
  the drawing stops framing lets go of the pointer at its next move.
  Slice B draws the building itself, in the reference's warm, rounded style and sharp at every zoom: a
  roof with a *MEZZANINE* sign, each floor plate as a storey with its elevator doors, a ground-floor lobby
  with its entrance, and the elevator cab in its shaft at the floor it is on. *Ride the elevator* glides
  the cab to the next floor together with the camera, and cuts it there under `prefers-reduced-motion`;
  the whole-building view takes in the roof and the ground lobby. The drawing is scenery: it shows no
  fleet state, and every floor of one building is drawn alike. `Tests\Feature\Lobby\TheBuildingIsDrawnAsTheReferencesSectionTest` holds the drawing.
  Each floor plate's arched windows now show the time of day, as the reference's windows do: the sky's
  gradient at full strength, stars and a moon at night, the sun by day, at dawn and at dusk, and a city
  roofline whose windows light up after dark. Behind the building the rest of the drawing shows the same
  sky, dimmed as in the reference. Until the page has been live, the windows and the backdrop show a flat
  sky with no stars, sun, moon or lit windows. It is the floor's own sky value on the floor's own driver:
  it follows the viewer's clock, moves only when a feed heartbeat arrives — never on a timer or a poll —
  and stops when the feed dies, just as the floor's windows do, and it steps to a new phase rather than
  fading, with reduced motion or without. Every sky on both pages is painted from one table in
  `floor/floor-layout.js`, so the floor's windows now show that table's gradient instead of flat colours
  — the lobby's own windows read the same table further, adding the stars, the moon, the sun and the lit
  city the floor's windows do not draw. That sky is
  the lobby's one animation: each heartbeat writes one A17 row to the page's animation log and nothing
  else. Both pages now take their animation log from `wire/live-page.js`, which bounds it with FLOOR.md
  § 12's retention figure, and the floor screen and the lobby share one sky driver, `RoomClock` in
  `floor/floor-layout.js`. `Tests\Feature\Floor\TheLobbySkyIsTheFloorsA17Test` holds the sky.
  The shared camera (`wire/camera.js`) gained `focusOn()`, a zoom to one rect inside what is framed, and
  the floor page and the lobby share one glide through `wire/camera-view.js`, one wheel-and-drag wiring
  through `wire/camera-gestures.js` and one keyboard-and-zoom-button wiring through `wire/camera-keys.js`:
  a drag that ends over a desk or a floor plate is never a click on it. AT-D3-21's building half is
  `Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`, the shared wiring is driven
  under `node` by `Tests\Feature\Floor\TheCameraWireIsOneForBothPagesTest`, and the plates' text size is
  held by `Tests\Feature\Floor\ThePlateNameIsReadAtTheBodyTextSizeTest`.

Older releases: `docs/changelog/<tag>.md`, one per tag.

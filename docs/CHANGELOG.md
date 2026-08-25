# Changelog

Every PR whose title or branch carries a `card#NNNN` token owes a line-initial
`- **card#NNNN** — …` bullet under `## [Unreleased]`, **in the same PR**. A PR that names no
card owes nothing. `docs/PLAN.md § 4` owns that rule and the reasoning behind it, including
why the bullet must be line-initial; `docs/VERSIONING.md` owns when a release collects these
entries and retitles the section.

Nothing has been released yet, so `[Unreleased]` is the only section.

## [Unreleased]

- **card#7686** — `tools/design/verify-fleet-state.py`'s G6 was RED on `dev`, and the defect was in
  the guard: its predicate read **any** `D2` mention in a D1 section as an unmarked obligation, so
  card#7338's `§ 6.5` doc-sync — which *cites* D2 § 6.4's existing `calls.synthesized` column as
  corroboration, and imposes nothing — was flagged as an obligation with no marker and no Appendix A
  row. Marking it `D2-MUST` would have been false and an Appendix A row would have recorded an
  obligation that does not exist. `docs/design/EVENT-SCHEMA.md § 1` now declares a fourth form,
  **`D2-CITED:`**, for a sentence that references D2 without constraining it, and G6 subtracts those
  lines. It is the one form that subtracts, so it is fenced three ways and each was seen to fail on a
  plant: an **unmarked** D2 mention still reds (silence is never the citation case — the S29 shape is
  untouched), an obligation marker on the same line wins over it, and a `D2-CITED:` line must name
  the place in D2 it cites and must carry no deontic language — an obligation cannot pass by wearing
  the marker. A new CONTROL holds D1 § 1's declared vocabulary and the tool's greps together, so a
  form renamed in the document and not in the checker reds instead of silently forgiving. Appendix
  A's derived 28/1 split is unchanged.
- **card#7338** — The batch ingest endpoint: `POST /api/ingest/events` and
  `GET /api/ingest/health`, implementing `docs/design/EVENT-SCHEMA.md § 12` — the eleven
  validation steps in their stated order, the error bodies of § 12.2, the four rate limits of
  § 12.3 (the failed-authentication one evaluated inside step 4, where its subject is reachable),
  atomic batches, per-event dedup and the § 12.7 counters. Per-seat `mzn_` tokens stored as
  SHA-256 only, issued and revoked by `mezzanine:ingest-token:issue` / `:revoke`. The routes carry
  **no middleware at all** — no session, no CSRF, no MFA and not Laravel's stock `api` throttle —
  so the surface is machine-to-machine in structure rather than by convention, and the separation
  is asserted both ways. Creates the store tables the ingest writes (`installs`, `seats`,
  `batches`, `events`, `seat_state`, the two counter tables); the fold and feed tables are
  card #7339's. Validated against the real `fleet-reporter` over TLS with certificate
  verification on (`server/tests/roundtrip/ingest-roundtrip.py`), which also drives AT-9 and
  AT-13's reporter half. Fixes a wildcard CORS header the unpublished stock config was applying
  to every `/api/*` route, including the MFA-gated snapshot. Doc-sync: `§ 6.5` gains the
  `synthesized` field row that `§ 6.6` has always mandated, and `§ 12.2` splits the one `422` row
  into the two codes `§ 12.1` actually names.
- **card#7337** — Ran D1's AT-1 against a real `/clear` on a real seat, and **it failed** — the
  reporter behaved exactly as `docs/design/EVENT-SCHEMA.md` § 8.2/§ 8.3 specify, while the design's
  guarantee did not hold on the installed harness (2.1.245). Three measured facts: a killed call
  **does** fire `PostToolUseFailure` (`Exit code 137`, `is_interrupt: false`) under the new
  `session_id`; a dispatched subagent runs as a **background task**, so the parent's turn ends clean
  while it works; and a conforming consumer therefore minted *idle* on a seat running a subagent.
  Amended in consequence: `D2-MUST` #1 and D2 § 4.3's `derive_activity` gain
  `background_tasks_open == 0` (and `session.end` clears that one component of `L`, so an idle that
  is **true** after the reap is not suppressed); § 6.6 gains a two-signal **kill signature**
  (`is_interrupt`, **or** exit 137 across a session boundary — exit 137 alone is an OOM kill and a
  genuine failure); § 8.6 refuses a cross-session late close and counts `late_close_cross_session`;
  § 6.0's re-capture obligation widens from minor to **any** version change, because a patch bump is
  what moved the lifecycle. AT-1 is rewritten against that lifecycle, with its idle assertion
  narrowed to the transitions that are false. New rig `tools/at1-kill-vs-complete/` drives the proof
  end to end under a scratch config, with a hermetic `selftest.py`, a RED plant that raises rather
  than silently applying nowhere, and an **operator-run** credential prerequisite the rig refuses to
  script. Whether the harness behaviour changed between 2.1.240 and 2.1.245 is **not established**.

- **card#7334** — Laravel application skeleton in `server/`, with mandatory MFA on stock
  packages (Fortify + `pragmarx/google2fa`). MFA gates three surfaces independently — the
  browser pages, the websocket handshake (`/broadcasting/auth`), and the REST snapshot route
  — through one middleware, `EnsureTwoFactorSatisfied`. Fortify's stock passkey and
  self-registration features are disabled: a passkey completes a login on its own, and
  Fortify logs an un-enrolled user in on a password alone, so `auth` by itself never meant
  "MFA-satisfied". Test-store isolation is pinned and guarded per
  `docs/design/FLEET-STATE.md § 6.2`. Repo layout decision recorded as D-16.
- **card#7340** — Ported munder-difflin's procedural character generator into
  `resources/characters/` as dependency-free ES modules, seeded from `(install_id, seat_id)` so
  a seat's character is identical on every browser and every reload with nothing stored. Added
  `docs/ATTRIBUTION.md` (the asset manifest), `resources/characters/LINEAGE.md` (upstream, the
  pinned commit, the reproduced MIT notice, and what was deliberately not taken), and
  `bin/asset-provenance.py` + its RED fixtures enforcing `docs/design/FLOOR.md § 10.1`'s two
  gates and AT-D3-12's lineage half. The new workflow is **not** a required status check.

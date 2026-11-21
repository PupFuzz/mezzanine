# Changelog

Every PR whose title or branch carries a `card#NNNN` token owes a changelog note **in the same
PR** — and since card#12935 the note is a file of its own, `docs/changelog.d/<card>.md`, holding
the one line `- **card#NNNN** — …` exactly as it will appear here. **A change PR does not edit
this file**: two changes built side by side then never touch the same lines, so neither conflicts
with the other. A PR that names no card owes nothing. `docs/PLAN.md § 4` owns that rule and the
reasoning behind it, including why the bullet must be line-initial;
[`docs/changelog.d/README.md`](changelog.d/README.md) states the note's format;
`docs/VERSIONING.md` owns when a release gathers the notes into its section here.

⛔ **Enforced by `bin/release-pr-guard.py`**, on every PR. **R4** (since 2026-08-30 — for seven
days before that it was prose, and six cards' work merged with no entry at all) asks for the
note of every card the branch or title names; the two cases that owe nothing are a PR into `main`
(the release gathers the notes) and a PR that REMOVES a card's note (a revert of unreleased work),
both argued in that file's docstring. **R8** (card#12935) refuses a change PR that adds a line
under `## [Unreleased]` here, or that carries a malformed note. **R5** holds this file's SIZE under
`1 MiB − the bytes it grew in the last 14 days`, so it reds while there is still time to archive
released sections rather than after the contents API has begun returning it empty. **R6**
(card#9707) compares a release PR against `dev`, so a release cannot quietly ship with fewer
notes or cards than `dev` holds; it accepts the release deleting a note only when that note's line
is in the release's copy of this file. **R7** (card#9814) refuses a release PR whose copy of this
file carries more than two released sections, and so enforces the archiving below. **R9**
(card#12935) refuses a release PR that leaves a note in `docs/changelog.d/` unassembled.

Sections are newest-first. `## [Unreleased]` stays empty on `dev`: what has landed since the last
release is the set of notes in `docs/changelog.d/`, and a release writes them here as its own
section, highest card number first (`docs/VERSIONING.md § Release flow` step 4).

This file holds `[Unreleased]` and the latest released section only. Every older release is one
file per tag under [`docs/changelog/`](changelog/) — `ls docs/changelog/` lists them — moved there
verbatim by release flow step 13 (`docs/VERSIONING.md`), which R7 enforces. R5 gates this file's
size; `docs/PLAN.md § 4` says why the archive files need no gate of their own beyond R7's backstop.

## [Unreleased]

## [0.15.1] — 2026-11-14

- **card#13033** — **Interns step onto their couch from the front, and off it the same way.** On the 2-D and the 3-D floor alike, an intern arriving at its seat walks to the spot straight in front of it and steps back onto the cushion; leaving, it steps straight forward off the cushion and walks away. Its path never crosses another seat, a couch's arms or its own couch, and a back-row intern reaches its seat along the aisle between the two rows of couches. On the 3-D floor the couches are slimmer front to back to leave that aisle, keeping their height and length, and an intern rises onto the cushion as it steps back instead of popping up once it has arrived.
- **card#13033** — **On the 3-D floor, every intern sits on top of its couch, between the arms.** A desk's side table is one couch per row of its seats, as the 2-D floor draws it; each couch is lengthened between its arms, which keep their thickness, so the first and last interns sit inside them with room to spare, and each intern's seat height is the couch file's own seat at the couch's scale, read from one record held to the file by the floor's world check.

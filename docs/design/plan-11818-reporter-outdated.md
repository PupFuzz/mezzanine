# card#11818 part 2 — build plan: a seat running an out-of-date reporter is visible

**What this file is.** The build plan for one card, written for the coder who implements it and the
reviewer who attacks it. It owns the *sequence* and the *runbook findings*; it owns no rule. Every
rule it names lives in the document that owns the surface, and this file points there rather than
restating it: the badge and its predicate are `FLEET-STATE.md § 7.2` (decision 59, AT-D2-30, Appendix B
row 14); its render is `FLOOR.md § 7.2` and `§ 5.1` (decision 75, § 14 item 41, AT-D3-30, Appendix B
row 25); the health member is `EVENT-SCHEMA.md § 4.1`; the version line is `docs/VERSIONING.md § The
reporter's own version`. Those amendments landed with this plan, in the same commit, so a reader who
finds the two disagreeing should trust the owning document and fix this one. **When the build has
landed, this file is history**: the Appendix B rows carry the landing markers, and the coder's PR may
delete it or leave it; nothing cites it as a rule.

The repository kept no `plan-*.md` before this one. Earlier cards planned in `docs/PLAN.md § 3`'s
work-breakdown table and in each design document's Appendix B rows; this file exists because the
dispatch asked for one place a coder can read top to bottom, and it keeps to what those two surfaces
do not carry — the order, the runbook fixes, and the acceptance list as observables.

## 1. The problem, in the product's terms

Every seat running `fleet-reporter` applies every reporter update (the operator's rule of 2026-10-13,
`fleet-reporter/README.md § Updating a seat`). Part 1 (PR #363) made a skipped update *detectable*: the
version moves on every change that ships, and the guard holds that. Nothing yet makes it *visible*:
the server stores what each seat last reported and compares it with nothing.

The population this is for, read from the sandbox store on 2026-10-24 (the command prints it; the
figures below are what it printed that day and will not be what it prints tomorrow):

```bash
cd server && php artisan tinker --execute='foreach (DB::table("seat_state")->join("seats","seats.id","=","seat_state.seat_ref")->join("installs","installs.id","=","seats.install_ref")->select("installs.install_id","seats.seat_id","seat_state.reporter_version","seat_state.server_badges","seat_state.reporter_degraded")->get() as $r) echo implode(" | ", (array) $r), "\n";'
```

It printed one seat at `0.2.0` carrying `["epoch_reset"]` and `["harness_contract_moved"]`, and one at
`0.1.0` carrying none — under a tree whose `REPORTER_VERSION` reads `0.3.0`
(`grep -o "^const REPORTER_VERSION = '[^']*'" fleet-reporter/fleet-reporter.js`). Both would be badged
by this build; the first is the case decision 75 is decided on.

## 2. The shape, and what was rejected

**Chosen — extend the badge primitive.** One new server-derived badge, `update_required`, raised by
`App\Fold\Badges::serverFor()` from `seat_state.reporter_version` against a new constant
`App\Ingest\SchemaVersions::CURRENT_REPORTER_VERSION`, through one predicate
`SchemaVersions::reporterIsOutdated(?string): bool`. The badge rides every surface a badge already
has — `badges[]` on the wire, the desk's badge row and flag, the drill-down's line, the desk list's
id, A11 on arrival, `badges_since`. No column, no migration, no new wire member on the seat object, no
new desk element. The constant is pinned to the artifact by a unit test and by the existing guard.
The floor's one rule change is the badge row's ORDER: treatment badges, then `update_required`, then
the wire's order (decision 75), so the mark is not the first badge folded into *⚠ +N*.

**Rejected — raise `MIN_REPORTER_VERSION` and refuse.** A refusal empties the one surface the rule asks
for (the seat goes dark within 900 s) and is permanent for the batch under D1 § 11.5. The seat ruling
is badge-only (`FLEET-STATE.md § 13` row 59).

**Rejected — a new mark on the desk** (a note on the monitor, a tag on the nameplate). It would be a
member added to the glance set the operator ruled closed (`FLOOR.md § 13` row 44's sibling, Q0), with a
rect, a z-order and an art decision the chip already settles; the chip's own text is the accessible
equivalent. Offered to the operator as option (B) in § 14 item 41, never built unasked.

**Rejected — the badge in the wire's order.** It fails the rule on the live population: the one seat
above with two D1 badges would show *⚠ +1* and no word. The picture of that shape — two badges ahead,
the update folded into the flag — is
`/home/mezzanine/.cache/coord/card-11818/preview-scratch_outdated_crowded-2560x1600.png`; the picture of
the chosen render is `preview-scratch_outdated_pair-2560x1600.png` beside it (the `1280x800` pair is the
reference viewport; both pairs were drawn before the id was renamed, so the chip text in them reads the first draft's longer id, `reporter_outdated` — the geometry is what they show, and the real id is shorter). Both were drawn with `tools/design/floor-fixture.browser.mjs` over a scratch
fixture and a scratch member-set entry, neither committed; the committed fixture is `fx-reporter`
(§ 4, step F3).

**Not windowed, deliberately.** The operator's ruling of 2026-09-14 (card#9491) windows badges raised by
a *counter* at 24 h so one bad minute does not mark a seat for life. This badge has no counter: it is a
comparison that holds until the seat reports a build that is not behind, like `clock_skew` and
`fold_lag`. `FLEET-STATE.md § 7.2` says so where the window is stated.

## 3. The build, in order

Each step names its check. S-steps are the server, F-steps the floor, G-steps the guard, R-steps the
runbook. S1 → S2 → F1 → F2 → F3; G and R are independent of them and of each other.

**S1 — the constant and its pin.** In `server/app/Ingest/SchemaVersions.php` add
`public const CURRENT_REPORTER_VERSION = '<the artifact's REPORTER_VERSION>';` beside
`MIN_REPORTER_VERSION`, with a docblock that says what `FLEET-STATE.md § 7.2` says (what it is, that it
follows the artifact, that it refuses nothing) and re-words `MIN_REPORTER_VERSION`'s *"Reported by …
and nothing else"* so it stays true. Add
`public static function reporterIsOutdated(?string $version): bool` — `null ⇒ false` (no reported build to be behind — a seat that has sent no batch, or one a rebuild found no retained batch for; `FLEET-STATE.md § 7.2` names both routes and the residual); a value not
matching `^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$` ⇒ `true`; else `version_compare($version,
self::CURRENT_REPORTER_VERSION, '<')`. Publish `'current_reporter_version' =>
SchemaVersions::CURRENT_REPORTER_VERSION` in `IngestController::health()`, and extend
`IngestHappyPathTest`'s health-body expectation with `'current_reporter_version' =>
SchemaVersions::CURRENT_REPORTER_VERSION` — the constant itself, never a literal beside the existing
`'min_reporter_version' => '0.1.0'`, which would make the test a third hand-synced copy of the version
that every reporter-bump PR must edit and `bin/reporter-version-guard.py` does not watch. Add
`Tests\Unit\Ingest\CurrentReporterVersionIsPinnedToTheArtifactTest`: read
`fleet-reporter/fleet-reporter.js` from the repository tree, match the one line
`^const REPORTER_VERSION = '([^']*)';$` (exactly one match, else fail), assert equality with the
constant. *Check:* the test is red with the constant edited one patch off, green as committed.

**S2 — the badge.** `Badges::SERVER` gains `'update_required'` last; `serverFor()` appends it when
`SchemaVersions::reporterIsOutdated($state->reporter_version)`; the class docblock's *"SEVEN ARE
DECLARED"* paragraph becomes a sentence that reads the count off `§ 7.2`'s list rather than stating
one. `Badges.php`'s `render()` docblock ("bounded at 18") and its class docblock's "SEVEN … ALL SEVEN" are
restated counts and are re-worded to read the count off § 7.2. `COUNTER_BADGES` is unchanged (no counter
raises it; `WINDOWED_SERVER_BADGES` stays equal to its keys). Add
`Tests\Unit\Fold\BadgesServerMatchesTheDocumentTest`: parse the `·`-separated backticked list under
`FLEET-STATE.md § 7.2`'s *"The server-derived badge set"* and assert it equals `Badges::SERVER` as a set,
in both directions, naming the member that is on one side only. Its plant is built in: the same parser
runs over the document text with the last member removed and over the text with a made-up member
appended, and the test asserts BOTH mismatches are reported (a parse that returned the empty set, or a
comparison that cannot fail, fails the plant). This is the check that holds the class to the document;
`SeatObjectMatchesTheDocumentTest` does not (its header says it checks no "types, bounds", and its
`flatten()` declares none for `badges`), so nothing here is gated on it. Add `Tests\Feature\Fold\AnOutOfDateReporterIsBadgedTest` — AT-D2-30's GREEN as written, and its
REDs as planted mutations the test re-runs against (the suite's own `mutatedModules` pattern for
PHP is a copied class; where that is heavier than the leg is worth, the RED is run once by hand and
its command recorded on the card). The deploy leg rebinds no constant: it writes a lower version
straight into a quiet seat's `seat_state.reporter_version` — the state a raised constant leaves
behind — and runs one sweep pass; no test-only seam in the predicate. *Check:* `BadgesServerMatchesTheDocumentTest` green as committed and red with `update_required`
removed from `Badges::SERVER` (run once by hand, command on the card), its in-test plant seen to report both mismatches; AT-D2-30's two badged seats and three unbadged ones.

**F1 — the member and its line.** `server/public/js/wire/member-sets.js`'s `BADGES` gains
`'update_required'`; `drilldown/drilldown-model.js`'s `BADGE_LINE` gains
`update_required: { origin: 'D2', line: 'reporter out of date — update required' }` (the first
italic span of `FLOOR.md § 7.2`'s row, verbatim — `TheDrillDownSpeaksTheDocumentsBadgeLinesTest`
holds it); its docblock's *"the eighteen lines"* becomes a phrase that reads the count off the table. **Every
restated badge count the build must move**, derived by
`grep -rnE "eighteen|\b18\b|bounded at 18|D2's seven|the 18 badges|SEVEN|badges at the bound" server/app server/public/js server/tests tools/design`
(the starting set, to be re-run and read, not trusted): `TheDrillDownSpeaksTheDocumentsBadgeLinesTest.php:94`
(`assertCount(18, $lines, …)` — it parses `FLOOR.md § 7.2`'s table, so it reds the moment this design
commit is in the tree; replace the literal with the count derived from the document, `BADGES.size` in
`wire/member-sets.js`, or both held equal), `wire/member-sets.js` lines 3 and 21 ("the 18 badges", "D2's
seven"), `AnUnrecognisedMemberRendersAsUnrecognisedTest.php:92`'s docblock ("§ 7.2's 18 badges"),
`drilldown-model.js`'s "eighteen lines", `Badges.php`'s two paragraphs (S2), and the planted entry
`planted: badges at the bound (18)` in `desk-leaves/fx-desk-leaves-planted.json` with its golden rows in
`fx-desk-leaves-before-11058.json` (a plant labelled with the bound it was at: re-label it without a count
and let it reach the bound by the document's list, then regenerate the golden rows through the probe
that derives them). `BADGE_BOUND` is already `BADGES.size` and moves by itself.
`badgeCount()` returns `null` for it (the `default` branch already does). *Check:*
`AnUnrecognisedMemberRendersAsUnrecognisedTest` and `TheDrillDownSpeaksTheDocumentsBadgeLinesTest`
green; each seen red first with the member missing from its copy.

**F2 — the row's order.** `server/public/js/floor/desk-layout.js`: add
`export const PINNED_BADGES = Object.freeze(['update_required']);` beside `TREATMENT_BADGES`, with a
docblock citing `FLOOR.md § 13` row 75, and make `clusterOrder()` return the treatment badges, then the
pinned ones, then the rest in the wire's order. `TREATMENT_BADGES` is unchanged: the desk is **not**
treated as not-current for this badge (AT-D3-30's second RED). Re-word the module's header lines and
the `BADGE_BOUND` docblock, which restate `§ 7.2`'s member count. *Check:* `TheNewDeskKeepsEveryLeafTest`
and `SeatFurnitureNeverOverlapsTest` green; the chip's text `update_required` fits the chip uncut: it measures 82.1 px at the desk's
`10px sans-serif` (canvas `measureText`, headless Chromium, 2026-10-24) against the 98 px a 104 px chip
leaves after its padding (`BADGE_W − 2 × BADGE_PAD`); `SeatFurnitureNeverOverlapsTest` *(e)* holds it at
whatever face the suite's host measures with.

**F3 — the fixture and the test.** `server/tests/Feature/Floor/fixtures/fx-reporter.json` as
`FLOOR.md § 11`'s fixture row states it, built from `fx-office`'s run shape (snapshot, building, room
map). `Tests\Feature\Floor\AnOutOfDateReporterIsMarkedOnTheDeskTest` — AT-D3-30's GREEN, its
REDs through `DrivesTheDeskFloor`'s `mutatedModules`, and its control. *Check:* each RED bites on its
own clause before the GREEN is trusted.

**G1 — the constant-equality leg** (`bin/reporter-version-guard.py`). Add `SERVER_VERSIONS =
"server/app/Ingest/SchemaVersions.php"` and `CURRENT_RE` for its one declaration line. In `judge()`,
BEFORE the not-applicable return: read both files at the head; the server file missing or declaring
the constant other than exactly once ⇒ `Unmeasurable` (exit 2); the two values unequal ⇒ a finding
(exit 1) naming `CURRENT_REPORTER_VERSION`, so a PR that bumps the artifact alone and a PR that edits
the constant alone both red, whatever else they touch. Selftest: the temp repo gains a copy of the
server file; **`bump(v)` itself moves both the artifact and the constant** — every bump site calls it (`control-js-bump-row`,
`control-fixture-added-row-names-hooks` and `control-two-bumps-union-covers` all bump `REPORTER_VERSION`, expect rc 0 and
would otherwise return rc 1 under the new leg), so one change keeps them all honest; a second helper,
`bump_artifact_only(v)`, is used by `plant-bump-server-constant-stale` alone; new case
`plant-bump-server-constant-stale` (artifact bumped, row added, constant untouched) expects rc 1 and
the text `CURRENT_REPORTER_VERSION`; new case `plant-server-constant-missing` expects rc 2. *Check:*
`python3 bin/reporter-version-guard.py --selftest` prints every case `ok`, and the judge against
`origin/dev` on this branch prints `NOT APPLICABLE` only once the constant equals the artifact.

**G2 — finding 1, the reworded install line.** Add `INSTALL_ANY_RE = re.compile(r"^install\b.*<checkout>/",
re.M)`; in `judge()`, any line it matches that `INSTALL_RE` does not ⇒ `Unmeasurable` naming the line
(exit 2) — a reworded line drops out of the population loudly instead of silently. Selftest: new case
`plant-install-line-reworded` (one `install -m 0600` line changed to `-m 0640`) expects rc 2 and the
text `not in the form the guard reads`; the existing `plant-no-population` keeps its rc 2 and its
text. *Check:* both cases `ok`; the docstring's population paragraph names the new refusal.

**G3 — finding 4.** `.github/workflows/reporter-version.yml`: `types: [opened, reopened, synchronize,
edited]`, with one comment line saying a base retarget re-runs the guard. *Check:* the workflow's
`on:` block, read back.

**R1 — finding 2.** `INSTALL-LINUX.md` U5: *"Step 6 below"* → *"Step 6"* with its link
(`#step-6--selftest`), which stands above the upgrade section.

**R2 — finding 3.** The two bare counts — *"each of the five helpers"* (U2's check and the *What was run*
list) — become *"each helper U2's list names"*; U2's own `for h in …` line is the list.

**R3 — finding 5, the home directory.** Every `/home/mezzanine` inside a code block becomes `$HOME`,
**except** Step 5's `LINE="…"` assignment and its `printf` block: those are the guard's crontab
population, and a byte change there demands a `cron` touch and a version bump for a seat that applies
nothing new. Where a block interpolates the path into a string another process reads (`FR_*`
variables, `node -e` arguments), `$HOME` must stay unquoted-expanded — read each block for a `'…'` that
would carry it literally. The *values to substitute* table keeps its literal example row. *Check:*
`grep -n "/home/mezzanine" fleet-reporter/INSTALL-LINUX.md` prints the table row, Step 5's two sites,
and the recorded *what was run* attempt names, and nothing else; Step 0's block run against a scratch
`HOME` prints both `copied` lines.

**R4 — finding 6, `<checkout>` defined.** U0 becomes *back up, and clone the target ref*: after Step 0's
block, `git clone https://github.com/PupFuzz/mezzanine "$B/checkout" && git -C "$B/checkout"
checkout --detach <commit> && git -C "$B/checkout" rev-parse HEAD` (a commit, which `--branch` cannot
take), with the check that the printed SHA equals the announcement's `<commit>`,
and the sentence *from here `<checkout>` is `$B/checkout`*. The intro paragraph's *"written `<checkout>`
as in the rest of this runbook"* points at it. *Check:* the clone's `rev-parse` equals the SHA the
announcement names; U2's `awk` reads the clone's runbook.

**R5 — finding 7.** U6 gains one sentence before its block: *when U5 wrote a new crontab its block
already stopped and restarted the flusher; U6's stop is then a second stop, expected and harmless —
run it, because its check is what proves the lock holder runs the installed artifact.*

**D1 — docs in the same PR.** `fleet-reporter/README.md § Updating a seat`: one sentence — a seat
whose last reported version is behind the server's is badged `update_required` on the floor and in
the drill-down, and its batches still land. `docs/CHANGELOG.md` is release-only and is not touched.
`claim-ledger.py --base origin/dev` over the diff; every restated count the code comments carry
(F1's list above — the one derivation) is in its ledger.

**P1 — the PR, and design and build ship in ONE PR.** The design commit alone cannot merge to `dev`:
`TheDrillDownSpeaksTheDocumentsBadgeLinesTest` parses `FLOOR.md § 7.2` and counts its rows, so the new
row reds it until F1 lands. The branch's design commits and the build commits are one PR. Title `card#11818: a seat running an out-of-date reporter is badged on the floor and
the desk (closes card#11818)`; body from the helper script; `Built:` with the designer slot. The guard
prints `NOT APPLICABLE` on this PR (no artifact, fixture or crontab change) and its constant leg passes.

## 4. Acceptance, as observables

1. `php artisan test --filter CurrentReporterVersionIsPinnedToTheArtifactTest` is green; with the
   constant edited one patch off it is red naming both values.
2. `python3 bin/reporter-version-guard.py --selftest` prints `ok` for every case including
   `plant-bump-server-constant-stale` (rc 1), `plant-server-constant-missing` (rc 2) and
   `plant-install-line-reworded` (rc 2).
3. `curl -H "Authorization: Bearer <seat token>" <ingest>/health` returns a body whose
   `current_reporter_version` equals the tree's `REPORTER_VERSION`.
4. On the sandbox after deploy, the query in § 1 prints `update_required` inside `server_badges` for
   every seat whose `reporter_version` is lower than the constant, within one sweep pass of the deploy
   and with no batch from the seat; and the floor shows the chip on each such desk ahead of any
   non-treatment badge.
5. Upgrading one such seat (`INSTALL-LINUX.md § Upgrading an existing seat`, U0–U8 as amended) clears
   the badge on the first event its new build sends — observed as a `seat.delta` whose `changed[]` names
   `badges` and whose array lacks the member, before the next sweep.
6. A seat fed a batch with `reporter_version` `"0.3.0-dev"` is badged; a seat with no batch is not; a
   seat ahead of the constant is not (AT-D2-30's GREEN, in the suite).
7. The drill-down for a badged seat shows the line *reporter out of date — update required* with no
   window text and no counter, and the reporter block's version beside it; the desk list prints
   `badges: update_required` (AT-D3-30's GREEN, in the suite).
8. `tools/design/verify-floor.py`, `verify-fleet-state.py`, `verify-event-schema.py` and
   `verify-design-docs.selftest.py` are green on the PR head; `bin/no-roundtable-refs.py` is green.

## 5. What could falsify it, and what is left undefended

- **A seat ahead of the server.** Not badged, by design; a reviewer who expects a mark there is
  reading the server's deploy lag as the seat's omission. The honest surface for that is the release.
- **The sweeper down.** Then no deploy re-judges anything — but a dead sweep is already `fleet.sweep:
  stalled` and every time-derived transition is frozen with it; this badge adds no new failure, and the
  fold still raises and clears it on the seat's own events.
- **A version at exactly 24 B.** D1's bound; the grammar accepts any length of digits, and
  `version_compare` compares numerically. A 24-byte `x.y.z` is a seat lying about its build, and it is
  compared like any other.
- **Both treatment badges present AND behind.** The update mark is in the flag (*⚠ +1*): named in
  decision 75 as the one residual, on a desk already hatched and dimmed, whose panel names it.
- **The operator reverses decision 75** (chooses (A)). The badge and every other surface stand; the cost
  is `PINNED_BADGES` emptied, AT-D3-30's GREEN inverted and its first RED turned into the GREEN's clause,
  and the doc rows `FLOOR.md § 13` row 75 lists. Choosing (B) undoes none of it.
- **Not defended: a reporter that reports a current version it does not run.** The version is the
  reporter's claim about itself; a tampered artifact is outside what any badge can see, and the
  announce reply's sha256 is the check for that (`UPDATES.md § Announcing an update`).

## 6. Across the boundary (canon #7)

The seat DECLARES its build (`reporter_version` on every envelope, D1 § 4.2); the server CHECKS it
against the tree it was built from and declares that tree's version on the health endpoint. What
neither end can verify locally: that the seat's declared version is the build it runs. That is named
above as undefended and is what the announcement reply's hash exists for.

## 7. The question for the operator

**The badge row's order** — `FLOOR.md § 14` item 41, decision 75. The seat ruling pins
`update_required` between the treatment badges and the wire's order; the operator's 2026-10-02 ruling
(Q1 B) ordered the row *treatment first, then the wire's order*. The letters are `FLOOR.md § 13`
row 75's, which owns their text: **(A)** keep the ruled wire order, **(B)** a separate mark outside the
row (its own card), **(C)** the pin as designed. **Recommendation: (C)**, built now; (B) can be added
beside it later without undoing anything, and (A) costs what row 75 lists.

## 8. Out of scope, named

- Naming the target version in the drill-down line would put the server's constant on the fleet health
  object (a ninth member of a surface three endpoints carry byte for byte, D2 § 8.2.4). Not built: the
  announcement names the version and the health endpoint carries it for the seat's operator.
- A `selftest` check on the seat that reads `current_reporter_version` and fails when behind — a
  reporter change, so a version bump and a ledger row of its own. A sensible follow-on; not this card.
- Raising `MIN_REPORTER_VERSION`. Decision 59.

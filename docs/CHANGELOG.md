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
returning it empty.

Sections are newest-first: `[Unreleased]` collects what has landed on `dev` since the last
release, and a release retitles it (`docs/VERSIONING.md § Release flow` step 4).

## [Unreleased]

- **card#8174 (part 2)** — **`docs/CHANGELOG.md`'s per-PR bullet rule and `docs/PLAN.md § 4`'s
  changelog size gate were both stated in the present tense and neither existed.** The bullet rule
  — every PR whose title or branch carries a `card#NNNN` token owes a line-initial
  `- **card#NNNN** — …` entry under `## [Unreleased]`, in the same PR — was prose for seven days,
  and a sweep of `dev` found **six cards across seven merged commits** with no entry, including the
  entire fleet-reporter (`card#7335`) and all three D-documents. ⛤ **Stated precisely, because the
  first count was wrong: those six split three and three.** `docs/CHANGELOG.md` was created
  2026-08-25 (`ceea110`); `card#7455`, `#7521` and `#7457` merged BEFORE it existed, so they broke
  no rule and R4 correctly reports *cannot measure* on them. **Three of three card-bearing PRs that
  merged after the rule existed missed the bullet** — a worse compliance rate than the inflated
  figure suggested, not a better one. **R4** now asserts the rule on every PR, reading its card
  grammar at run time from `CARD_RE` in `bin/promote-cards-by-token` — the same line
  `card-token-lint.py` reads, so the accept-set has one home and no copy to drift. Two carve-outs,
  both keyed on something observable rather than on a declared intent: a PR into `main` owes nothing
  (release step 4 has just emptied the section **by construction**), and a PR that REMOVES a card's
  bullet is exempt for that card (a revert of unreleased work owes the deletion, not a second
  entry) — which cannot be claimed without deleting a visible line. **R5** implements `§ 4`'s own
  formula literally — `threshold = 1 MiB − the bytes this file grew in the last 14 days`, growth
  **re-measured from git history on every run and never stored** — and refuses only a PR that makes
  an already-over-threshold file bigger, so the archiving PR that fixes it is not itself refused.
  It was built rather than withdrawn because the file went 1,182 B → 150,881 B in five days, which
  puts the contents-API truncation cliff about five weeks out rather than years. **Nine mutations
  were each seen to red**, and R4 was replayed against real history: it refuses PR #41 exactly as it
  merged and passes at the commit that backfilled it. ⛤ **Two defects in the guard itself surfaced
  only on the real surface, not in review:** `git fetch --depth=1` **re-shallows a repository cloned
  in full** — a six-commit branch collapsed to two, so R5 would have exited 2 on every PR while
  `fetch-depth: 0` sat above it looking correct — and one selftest arm asserted something false
  about itself, since a `--depth 3` clone of a three-commit history is a complete clone.

- **card#7947** — **`verify-harness-facts.py`'s `COMMON` was a hand-transcribed list of nine
  harness payload key names, and it backed most of that gate's fixture-key assertions.** The build
  declares those fields ONCE, on a base schema every hook declaration intersects
  (`<base>().and(<obj>({hook_event_name:…}))`), and the tool's key walker only ever entered the
  hook-SPECIFIC half — so the transcription was never compared against the binary at all. ⛔ **That
  is measured, not argued: 126 of the gate's 186 fixture-key assertions resolved against that
  list**, and a copy of the real 2.1.247 bundle with `cwd:e()` rewritten to `dwc:e()` inside the
  base schema — two bytes, the file's only difference from the installed build — **passed the
  pre-change gate, `HARNESS-FACT CHECKS PASS`, exit 0**. A renamed common key could not red it,
  which is the decoration canon #9 names; it is the fifth instance of the class card#7930 fixed
  four of, and the largest by assertion count. **The base schema is now RESOLVED out of the
  binary** at each hook's own declaration site — module-scoped like § 6's enum resolution, because
  `h=` has 5009 assignments in a 2.1.247 bundle — and `hook_event_name` is a CAPTURE of the text
  the hook pattern matched rather than a second spelling of it: **104 of the 186 assertions now
  resolve against the derived base set and 22 against the captured discriminant. No harness key
  name is written anywhere in the file.** ⭐ **Seen to fail, on the real bundle, before being
  trusted**: the planted rename reds with 22 failures each naming `'cwd'` and the fixture that
  carries it; a declaration stripped of its base prefix aborts naming `['Setup']` rather than
  guessing which common fields it has; and the new fabricated-builder control, pointed at a REAL
  builder, aborts saying it would confirm anything. **Both in-tool control legs name no field** —
  one spelled `"cwd" in common` would be the transcribed list back again, wearing an assert.
  ⚠ **One primitive fixed on the way, because the derivation made it hot**: the key walker
  re-sliced the whole remaining 47 MB of bundle at every depth-0 comma (`re.match(pat, s[i:])`) —
  the same defect `_assignments` records one screenful below, wearing a slice instead of an
  unanchored `\b`. Anchored as `pattern.match(s, i)` the whole gate went **8.9 s → 4.9 s** on
  2.1.247, so the added derivation costs less than nothing. ⚠ **Still NOT wired into CI, and not
  proposed for it** — the gate is fail-closed on the installed build D1 declares and card#7929's
  reasoning is unchanged, so this was validated locally against **Claude Code 2.1.247**, the build
  D1's five declaration sites agree on, and against two planted copies of it. ⚠ **The derivation
  deliberately did NOT move into `tools/design/d1_appendix.py`**: that library is the one parser
  for § 17's MARKDOWN and has two callers because both need that grammar, whereas
  `bin/harness-fixture-drift.py` never opens a bundle — hoisting a binary extractor into it would
  put a second, unrelated subject in a shared library to serve one caller.
- **card#8075** — **D3 § 7.1's `blocked` desk rendered *since 14:31* from NO D2 MEMBER, and the
  ratified floor-preview had already invented `blocked_since` to draw it.** D2 § 8.2.1's seat object
  declared nothing carrying when a wait began: the open attention request lives in § 8.2.3's
  `detail`, which is the drill-down's source and not the desk's — so the one rendered fact on that
  desk stood against § 5's own rule that a rendered fact with no field is a fact the client invented.
  ⭐ **The measurement that decided it, and it points the opposite way from the cheap fix**: a
  NON-live blocked desk already carries a real time through § 7.3's currency label, and a live one
  carries no currency label at all — so dropping the timestamp would have made the **stale** desk the
  better-informed of the two. The other candidate member is worse than absent: `activity.last_event_time`
  equals the request's time at the instant the hand goes up and then moves with the next activity
  event of any kind, silently re-dating a forty-minute wait. **card#8075's ruling took the expensive
  answer**: D2 § 8.2.1 now declares **`blocked_since`** — `rfc3339_ms`, nullable, non-null only when
  `activity_state == "blocked"`, carrying the open request's `event_time` on the seat's own clock,
  the same basis § 4.7 already measures the 60-minute attention ceiling from. It is a **PROMOTION,
  not a new fact**: `attention_requests.opened_at` is a stored column the seat row already points at
  through `open_attention_ref`, so no DDL, no event and no derivation rule moved, and § 8.2.3's
  *"~1.5 KiB of counters on every seat"* objection is priced on a drill-down payload, not on a member
  that is `null` on every seat that is not blocked. **The preview's invented field is reconciled in
  this change** — the spelling it minted is the ruled one, and both it and its README now say which
  it is. D3 carries the render row (§ 5.1), the null render (§ 5.6), the seat-clock listing (§ 2.4)
  and the § 7.1 cell's citation; the card#7966 bullet that held that cell OPEN is closed.
  ⛔ **What was deliberately NOT built: a server-clock twin, and therefore no age.** The desk draws a
  labelled seat-clock timestamp and § 2.4's four durations are still four; *waiting for 40m* would
  need a basis D2 does not publish, and minting one is a product question this card did not put.
  **Every derived figure moved and every one was re-measured, not re-typed** — the seat object
  1,807→1,828 B, the worst case 5,529→5,572 B, the worst-case delta 6,112→6,171 B and with it D3
  § 8.1's whole cap arithmetic (spare 2,080→2,021 B; the cap could still reach 15, and 16 still
  breaches). ⭐ **The gate's own count of the population it checks was stale the moment the member
  landed**: § 12 said *73 field names* beside a tool that re-derives 74 and reported clean, so
  `verify-fleet-state.py` G2 now holds that number against the table too. **Both directions of G2
  and the new count guard were each planted and watched red** before being trusted.
- **card#7946** — **`fleet-reporter/fixtures/hooks/` vendored D1 § 17 verbatim and nothing
  checked that the two agreed.** Editing § 17 alone left the fixtures stale with EVERY CHECK
  GREEN: the reporter's own `harness_payload_keys` check reads the FIXTURES (so it validates
  the copy against itself) and `verify-harness-facts.py` reads the APPENDIX (so it validates
  the original against the harness binary) — neither can see the seam. ⭐ **That is measured,
  not hypothesised: it diverged during card#7930, under the implementer's own hands, with
  every gate green, and was caught only because they happened to be editing both ends.**
  **`bin/harness-fixture-drift.py` + `.github/workflows/harness-fixture-drift.yml`** close it
  by making the fixtures a DERIVATION rather than a copy: the guard regenerates all fifteen
  files from the appendix — payloads grouped by `hook_event_name`, in document order, tagged
  with the `_source` their region declares — and requires the committed bytes to be exactly
  that, with `--write` repairing from the authority instead of by hand. ⛔ **Nothing about
  § 17 is retyped in the guard**: the appendix is located by its own heading and its section
  number is derived, the capture/stub split is read off which side of the DOCS-CITED heading
  a payload sits on, and every failure to READ the authority is **exit 2 with the reason** —
  heading renamed or duplicated, stub subsection gone, a payload parked where no region
  classifies it, an empty region — kept distinct from exit 1 so a broken gate never reads as
  a wrong fixture. **§ 17's grammar now has ONE spelling in this repo**: the shared parser
  `tools/design/d1_appendix.py`, which `verify-harness-facts.py` now uses in place of its own
  copy (same output, verified against 2.1.247 before and after), because a second parser is
  free to disagree about what the appendix contains and that disagreement is this same defect
  one level up. **Every arm was seen to fail** — nine drift arms, eleven fail-loud arms, and a
  meta-control that mutates each of the fifteen fixtures in turn and requires each to red
  ALONE naming itself, so the one green over fifteen files cannot be a comparison that
  silently skipped one. ⚠ **The gate runs but does not BLOCK** — both rulesets still require
  only `card-token-lint`. ⚠ **Nothing had drifted at the time of the fix**: today's fixtures
  are byte-identical to the appendix, so this closes the hole rather than repairing a break.
- **card#8161** — **D3's honesty principle was attributed to the operator, who disclaims it, and
  cited to a document nobody in this repository can open.** `FLOOR.md § 6.1` read *"Every animation is
  driven by a real event, or absent. — operator, via the proposal"*; the operator's verbatim ruling of
  2026-08-30 (card#7953) is *"I never forbade motion that is neither held by a delivered field nor
  caused by a delivered motion. Actually a little extra motion once in awhile is a nice touch.
  Therefore blinking LEDs on a server rack is ok as long as it is not distracting"*, and **the
  proposal is not in this repo** (tree-wide search at `dev`, with a control filename returning 0). ⛔
  **The attribution is why the rule was never re-argued** — a rule an operator handed down is not
  reopened, and a source no reader can fetch is not checked, so the design's own argument for it went
  unexamined for four revisions while the ratified reference artifact rendered motion the document
  forbade. ⭐ **The rule now has ONE home** — `§ 6.1`, as *this document's* design principle borrowed
  from the prior art `README.md` names — and the three other sites **point** rather than restate
  (`README.md`, whose *"borrowed from prior art"* framing was the honest one and is now the model;
  `docs/PLAN.md § 2`; `FLOOR.md § 0` item 3). **No site attributes it to the operator.**
  ⭐ **§ 6.3 relaxed, by SCOPING rather than loosening**: `§ 6.2` stays the closed set — for motion
  that makes a **claim**, which is the half a viewer must be able to trust, so a `working` desk still
  means working. Decorative motion is admitted and decided by **three property tests**, not a list of
  names: does anything **read** it (decision 21's surviving constraint — the wall clock was refused
  because `AT-D3-6` reads it and *"a frozen clock is not a defect and not a lie — it is the claim"*);
  would it **assert something false on a dead feed**; could a viewer read it as one of the seventeen
  (motion on an element a row draws is claim-bearing whatever drives it, which is what keeps
  `AT-D3-1`'s idle-breathing RED red). ⚠ ***"Not distracting"* is given an operational form** or it is
  a condition no reviewer can apply: **≥ 2 s cycle, opacity/scale only with no change of position, and
  never a row's visual vocabulary** — a bound written to admit what the operator ratified (`.glowpulse`
  at 2.4 s, 85%→55%) rather than to re-litigate it, with the 2 s carried in `§ 12` as **Chosen** and
  saying so. Position is excluded because *an avatar walking IS the status*, which is also why moving
  clouds and passing NPCs stay refused — by the amplitude bound, not by the claim test. **Three things
  the card did not ask for and the amendment owes anyway:** `§ 6.2`'s *"everything that moves without a
  delivered field is driven by the heartbeat"* note — which asks in its own text to be re-derived at
  every amendment — **went false** and is re-derived with what `AT-D3-6` does and does not lose;
  `§ 6.4`'s reduced-motion mechanism is **per-§ 6.2-row**, so decorative motion was outside it and now
  stops under `reduce` explicitly; and `§ 6.3` bullet 2 (*motion driven by a timer*) would have
  re-forbidden every decorative loop, since a loop with no driver is exactly what decoration is.
  ⚠ **Stated rather than covered over: no mechanised check reaches decorative motion** — it writes no
  animation-log row, so `AT-D3-1` cannot see it, and `verify-floor.py` reads the document and never
  opens `floor-preview.html`, which is how `.glowpulse` sat unnoticed (card#7929: wiring the verifiers
  is necessary and not sufficient). Review is what stands there, and saying so is the condition of
  taking the option. **No change to `floor-preview.html`** — card#7953 ruled all three sites stay.
- **card#7929** — **`tools/design/` was the only directory in this repository CI never entered:
  246 KB of verification code referenced by zero workflows and zero hooks, while D1/D2/D3 cited
  those tools as *"reds the gate"* in the PRESENT TENSE some fifty times between them.** The
  convention behind it was a runbook sentence in `tools/design/README.md`, and **a runbook
  protects nobody who does not follow it** — every D-document PR to that point merged on a gate
  that never fired. **Four gates now run on every pull request**: `verify-event-schema.py`,
  `verify-fleet-state.py`, `verify-floor.py`, and `floor-preview.selftest.mjs`, all pure repo
  reads on stock `python3`/`node` — no credential, no network, no interpreter pin to keep honest.
  ⛔ **Two are deliberately NOT wired, and named rather than skipped**: `verify-harness-facts.py`
  is fail-closed on the installed Claude Code build D1 declares, and `floor-preview.browser.mjs`
  on a headless Chromium; a stock runner carries neither, so wiring either would red every PR on
  correct work — **and a gate that reds on correct work gets disabled**. Wiring them as a SKIP
  would be worse: an *"N/A"* that reads green is a check reported as passed that never ran, which
  is this very defect one layer down. ⚠ **No `paths:` filter, for a MEASURED reason** —
  `verify-event-schema.py` resolves `docs/VERSIONING.md`'s path references and reds on any that is
  missing, so a PR deleting `bin/release-pr-guard.py` breaks D1's gate while touching nothing under
  `docs/design/`; a filter would pass that PR and leave the next unrelated one holding the red.
  **No `branches:` filter either** — a filtered workflow produces no run, which as a required check
  reads as PENDING, not passed. **`verify-design-docs.selftest.py` keeps the three document gates
  from becoming decorations**: it copies the tracked tree, plants a defect of each verifier's own
  headline class and requires a red naming the plant, asserting a DIFFERENTIAL rather than an
  absolute pass so a PR that legitimately reds a verifier still gets its real message instead of a
  control failure. ⚠ **A hole neither gate closes is declared rather than left implicit**:
  `verify-floor.py`'s G1 holds `§ 6.2`'s closed animation set against the DOCUMENT only and the
  artifact gate reads no animation at all, so **an animation in `floor-preview.html` with no row in
  `§ 6.2` is invisible to both** — which is how `.glowpulse` sat unnoticed (card#8161).
## [0.2.0] — 2026-08-30

- **card#8174** — **`docs/VERSIONING.md` specified the release act in twelve numbered steps and
  nothing enforced any of them.** On 2026-08-30 PR #38 merged `dev` → `main` green, breaking three
  documented rules at once: the head was the integration branch (which `delete_branch_on_merge`
  would have DELETED — the ruleset backstop the doc says *"do not lean on"* is what appears to have
  held, and that path had never been taken before), `VERSION` was unmoved, and there was no
  changelog section. `auto-tag-version` then failed **after** the merge — a correct report arriving
  at the one moment it costs the most, because the commit is already on `main` and the only remedy
  left is another release PR. ⭐ **The fix gates WHAT is merged, not WHO merges it**: one GitHub
  identity is shared by the agent and the operator here, so no actor-based control — CODEOWNERS, a
  bypass list, a required reviewer — can tell them apart, and every such rule would either block the
  operator or admit the agent. A content gate is actor-independent, free, and states its verdict
  while the fix is still one commit. **`bin/release-pr-guard.py` + `.github/workflows/
  release-pr-guard.yml`** assert three rules on any PR whose base is `main`, and nothing at all on a
  PR into `dev`: **R1** the head ref equals `release/` + the tag this PR would mint, **R2** `VERSION`
  is strictly greater than `main`'s by semver precedence, **R3** `docs/CHANGELOG.md` carries a
  section naming the new version. ⛔ **Every fact it needs is DERIVED, never retyped** — the release
  branch from `on.push.branches` in `auto-tag-version.yml`, the tag form from `.release-pr.json`'s
  `tag_format`, and the accepted `VERSION` spelling from that same workflow's own validation regex,
  because a private looser copy of it would green a release the tagger then reds post-merge, which is
  #38's harm shape rebuilt by the gate meant to prevent it. Every extraction failure is **exit 2, no
  fallback to a guess**, and exit 2 is kept distinct from exit 1 so a broken guard does not send an
  author to rename a branch that was fine. ⭐ **Refuses PR #38's real shape on all three rules**,
  measured against the real commits (head `7139cd7`, `main` `556ac3f`) as well as a hermetic replay;
  a well-formed release PR passes, so the reds are evidence rather than a check that always fails.
  Every rule and every fail-loud path was seen red against a single-variable mutation of that
  control, and the selftest caught one real defect while doing it: the R3 boundary was refusing a
  legitimate `## v0.2.0` heading. ⚠ **The gate runs but does not BLOCK** — both rulesets still
  require only `card-token-lint`; requiring context `release-pr-guard` is a one-line ruleset edit,
  and the workflow deliberately carries **no `branches:` filter** so it is safe to require (a
  filtered required check produces no run, which reads as *pending*, never *passed*). Steps 5, 6, 8,
  9, 11 and 12 stay unenforced on purpose, named in `docs/VERSIONING.md` rather than covered by a
  gate that would claim to have checked a judgement when it had checked a string.
- **card#7966** — **D3's § 7.1 Label line column published strings that its own rule statements
  forbid, and the reason no gate could say so is that the COMPOSITION of those strings was published
  nowhere.** Two known instances, one root cause, and a sweep of all ten cells that found three more.
  ⭐ **The root-cause fix is a stated convention, not the two corrected strings**: § 7.1 now declares
  that its Label line column is a **worked instance and never a rule**, so where a cell and a rule
  statement disagree the **rule governs and the cell is the defect** — which disposes of instance N+1
  without a round trip — and that where two **rule statements** disagree neither the table nor its
  reader settles it, because picking makes one of two ratified statements silently dead.
  **`stalled`** read *API error — rate limit*: § 7.6's **phrase** with the raw wire value **elided**,
  against § 7.6's own column heading *The line beside the raw value*, § 5.4's *"the line carries the
  raw string either way"*, § 5.1's *rendered verbatim* and **the same row's own `Never` column**
  (*"`api_error_type` is always on the line"*) — one row contradicting itself, and five statements
  outranking one example. ⛔ **Correcting the string was half of it**: the order and separators of
  raw value + phrase were stated by **no site in D3 at all**, which is exactly what let a second
  reading be minted, so **§ 7.6 now publishes the composed line once** — *API error — the raw value
  (the phrase)*, **(unrecognised)** in place of the phrase for a thirteenth value, **API error**
  alone for a null — and § 7.1's cell is a worked instance pointing there rather than a second copy.
  **`retired`** was the mirror image: § 3.5 asserted **one** string for **both** surfaces, in the
  words *"rendered on the desk's label line **and in full in** the drill-down"* — self-refuting,
  because a string that is *in full* on one surface is not the summary on the other. § 7.1's shorter
  string stands; § 3.5 now states **two strings for two surfaces**, each site naming the other, and
  answers the clock-basis question at source rather than by invention: `retired.at` is a **server**
  clock (D2 § 8.2.1), and D3 labels a **seat** clock precisely because it is another machine's claim,
  so neither string takes a label — exactly as the `stale` desk's *no data since 14:18* carries none.
  **A third site was corrected on the same reading:** § 5.1's `api_error_type` row said the value is
  *rendered verbatim* and then illustrated it with *rate limit* — § 7.6's **phrase**, where the raw
  value is `rate_limit` — so the site stating the rule was showing the reader the value the rule
  forbids.
  ⭐ **The class now has a gate that did not exist: `verify-floor.py` G11**, an eleventh guard class
  holding a **worked example against the rule statement governing it**. § 7.6's twelve member/phrase
  pairs are re-derived from that table on every run and both rendering sites are held against them;
  nothing is stored, and each predicate is **fed its own defect on every run** and must reject it.
  Seen to fail three ways before being trusted, each on its own message and each restored: § 7.1's
  cell reverted to the pre-fix string, § 5.1's illustration reverted to the phrase, and § 7.6's
  phrase drifted with § 7.1 left untouched — the third proving the guard catches drift from **either**
  end, which is why its message now names both sides instead of blaming § 7.1.
  ⚠ **The downstream carve-outs went with it, because an exemption outliving its reason is a
  permanently weakened check wearing a stale justification**: `stalled` is no longer in
  `floor-preview.selftest.mjs`'s `LABEL_NOT_COMPARABLE` (three members, now two), its literal is
  compared to § 7.1's cell like every other member's, and a new planted control re-mints the pre-fix
  elision **from the artifact's own composition** and requires the comparison to go red on it — it
  does, with *want "API error — rate_limit (rate limit)", got "API error — rate limit"*. The two
  property-based assertions that stood in for that comparison are gone as the weaker restatement they
  became, and the artifact's comment recording the tension now records its resolution.
  ⛔ **The sweep's remainder is NAMED IN THE DOCUMENT rather than left for whoever builds the floor**,
  because a clean column with an unnamed remainder reports where the searcher stopped: **`blocked`**
  renders *since 14:31* from **no field at all** — no § 5 row carries it and D2 § 8.2.1 declares no
  such timestamp, the open attention request living in `detail`, which is the drill-down's source and
  not the desk's — against § 5's own rule that a rendered fact with no field is a fact the client
  invented; **`working`** renders a descriptor § 5.1 assigns to the monitor, and states nothing for
  the A4 seat whose turn is open with no call, where that descriptor does not exist; and
  **`catching_up`** carries a second wording of the labelled seat-clock timestamp § 7.6 fixes once.
  All three turned on one question D3 did not answer — whether § 7.3's currency label and § 7.1's
  Label line are one rendered element or two — which is **rule against rule**, so the convention
  reserved it rather than letting the first round pick it.
  ⭐ **That question is now ANSWERED, and the answer closes two of the three: TWO rendered elements.**
  § 7.6's `activity_state` table — the section that **owns** that render form — says *under the label*
  in five rows; § 7.3's `catching_up` and `disabled` rows said *in the label only*. Rule against rule
  was rule against **defect**, and the sanity check is what makes it more than a word choice: read as
  one element, a `catching_up` desk's single line would carry *replaying history — last event 12:47
  (seat clock)* **and** *was: working (last event 12:47, seat clock)* — the same field, twice, in one
  line. **Both cells are corrected to *under the label***, and the sibling sweep found a **third** the
  ruling had not seen: § 7.6's own `link_state` table said *activity state in the label only* for
  `catching_up` too. § 7.3 now states the two-element rule and names § 7.6 as the form's owner, and
  § 7.6 says it owns it — so the next divergence has a governing statement to lose against instead of
  a second opinion to argue with.
  ⇒ **`catching_up`'s Label line drops the restated timestamp** — *replaying history*, the state
  sentence, with `activity.last_event_time` left to the currency label that owns it. **Not drift: a
  real redundancy the one-element reading hid**, because under that reading the repetition read as a
  paraphrase rather than as one field rendered twice on one desk.
  ⇒ **`working`'s Label line is *working*, the state's own sentence** — and the alternative was
  refused on the document's own rules rather than on taste. It may not be the descriptor (§ 5.1 gives
  that to the **monitor**, and that row's null case sends the monitor back to *the desk's state line*,
  so a repeat would point the fallback at a copy of what it stands in for and would say nothing at all
  for the A4 seat whose `action` is null); it may not be *turn open, no call* or any other wording of
  `open_turn` / `open_calls`, which A3 / A4's pose already renders; and it may not be **nothing**,
  because **AT-D3-5 and AT-D3-13 both assert all ten members are pairwise distinguishable BY LABEL
  LINE**. A bare state word is the only string left that renders `render_state` and no second fact.
  ⛔ **`blocked` is NOT fixed here, and that is the finding rather than an omission.** It renders
  *waiting on a human since 14:31 (seat clock)* from a timestamp **D2 publishes no member for** — the
  open attention request lives in `detail`, the drill-down's source, not the desk's. Dropping the time
  deletes the one thing a raised hand is for and inventing a field is what the preview already had to
  do, so it is a **D2 gap, not a D3 drafting error**, carried as its own item (**card#8075**) and live
  for the build (card#7341). The cell is untouched; § 7.1's remainder paragraph is the notice, and it
  **stays** — a document naming its own known-contradictory cell is the whole point of that paragraph,
  and the alternative is a document that reads complete while a cell is known wrong.
  ⚠ **The downstream carve-out moved again, and for the second time the exemption's REASON was what
  expired**: `working` is no longer in `floor-preview.selftest.mjs`'s `LABEL_NOT_COMPARABLE` (two
  members, now **one** — `unknown` alone, whose literals are the sibling table's). Its stated reason
  was *"the line is a wire field, not a fixed string"*, and D3 has now ruled that the line is not the
  wire field. Both newly-comparable literals owe proof the comparison can fail **on them**, so both
  got a planted control that re-mints the pre-fix expression from the artifact's own code: `working`
  reds at *want "working", got "Bash: composer test"*, `catching_up` at *want "replaying history", got
  "replaying history — last event 12:47 (seat clock)"*. Both run on **every** invocation.
  The artifact (`floor-preview.html`) follows D3 rather than the gate being relaxed to follow it:
  both labels are now constants. ⛔ **One honest consequence, declared rather than hidden:** the
  descriptor now has **no desk render in the preview at all**, because that file draws the monitor as
  a tint and not as text — § 5.1's monitor is unbuilt there, which is card#7341's to draw, and the
  timestamp's new home is a surface the artifact's own `D3_SCOPE` already declares *not implemented*.
  ⭐ **And the placement got a GATE, because a corrected string with nothing holding it is how the
  first two instances arrived.** `verify-floor.py`'s **G11** — still eleven guard classes, this is a
  second **fact** inside the one class it already names, *a worked example against the rule statement
  governing it* — now re-derives the placement phrase from § 7.6's five `activity_state` rows and
  holds every worked instance against it. **Nothing is stored:** the five rows must agree with **each
  other** (if they do not, the tool reports the rule as disagreeing with **itself** and judges no
  instance — that is a rule-against-rule amendment, not a defect in any example), the population is
  found **structurally** (any table cell carrying a *was:* span or naming the `activity state`, so a
  fourth site is in it the moment it exists), a run finding **none** reds rather than reporting clean
  over an empty set, and the predicate's own defect arm substitutes a preposition the rule does **not**
  use, picked from the recognizer's alternation. Seen red **six** ways, each on its own message with
  `FLOOR.md`'s md5 verified identical after every restore: each § 7.3 row reverted; § 7.6's
  `link_state` row reverted; one § 7.6 rule row drifted; **all five rule rows moved with the instances
  left behind — three reds, which is the leg following the OWNER rather than a string typed into the
  tool**; and the rule table's header renamed so the rule is never read at all.
  ⚠ **One carve-out, and it is a finding rather than a convenience: § 12's own guard-class rows are
  excluded by role.** A row documenting a guard necessarily **quotes the defect it guards** — § 12's
  G11 row quotes *in the label only* in order to say what was wrong — so a recognizer that read it
  **fails on the correction and passes a silent fix**, getting redder the more honestly the write-up
  is done. It fired exactly that way on this leg's own documentation row before the carve-out existed.
  § 12 renders nothing; G9 already excludes those rows by the same role.
  ⚠ **And one hole is DECLARED rather than closed**, because the fix for it was worse: the leg asks
  whether a cell **contradicts** § 7.6, never whether it states the placement at all, so a cell that
  re-words the placement out of the recognizer's vocabulary escapes by matching nothing. The stricter
  tier — every cell carrying a *was:* span must contain § 7.6's phrase — was written, run, and
  **removed**: it red on § 7.1's own corrected `catching_up` cell, which says the form is drawn
  *under this line* while pointing at § 7.3 and § 7.6. That cell is right; it **mentions** the form in
  order to say the timestamp is not the Label line's, and no structural test here can tell a mention
  from a placement, so the literal rule would have red on a careful paraphrase and passed a careless
  overwrite. All three limits are written into § 12's own Status cell rather than left in a commit
  message.

- **card#7897 (part 2, slice 1)** — **the coordination-event producer is designed in D1, and the card
  is corrected twice on the way.** `EVENT-SCHEMA.md` gains § 18: Mezzanine's own GitHub webhook
  receiver, deriving two fact objects — `coord.thread` and `coord.round` — from a coordination
  repository's deliveries. ⛔ **Correction 1: the producer is NOT the `agent-webhook-bridge`,** which
  the card specifies (*"all bridge-produced"*). § 1 non-goals a bridge dependency outright on D-10,
  and the card's route is additionally **unbuildable**: the bridge's `HandlerRegistry` resolves ten
  handlers and not one is a generic HTTP forwarder, so consuming its stream would have meant a
  feature request into another team's write-side actuator, on our critical path. ⛔ **Correction 2:
  two objects, not three** — `coord.message` was already collapsed into `coord.round` by the card's
  own ruling, and the brief re-listed all three; every coordination act is a post on a thread, so a
  third object is a second format for one fact. ⭐ **The bridge's classification behaviour was
  re-derived AT SOURCE** (`v0.77.0`, `f85b419`) as prior art, and § 18.3 records eleven findings with
  their file — DL-252's actor-vs-thread-author split, the three-member `AUTHORING_ACTIONS` allow-list,
  DL-002's shared identity, DL-035's frozen label, the body-`TO:` addressing rule, and DL-176's
  signed-body dedup key. ⭐ **The card's honesty audit is FALSIFIED in three places, and each is
  stated rather than designed around.** *(A)* The `from:`/`to:` labels are **not** a property of the
  `opened` delivery — the coordination repo's integrity Action materialises missing labels from the
  body *after* the issue exists, and the posting tool auto-adds a `to:` label to a live thread, so
  the addressee set is neither reliably present nor frozen; the bridge's own source records **641**
  such `issues.labeled` deliveries already dropped on the reference install. The derivation therefore
  keys on the body's `FROM:`/`TO:` lines wherever it needs an **address**, which is the scope the
  protocol makes them authoritative for — ⚠ **not membership, which is label-authoritative, as the
  round-4 bullet below establishes and this line originally elided.**
  *(B)* **A convergence has an act and may have no actor**: `issues.closed` is not an authoring
  action, so under shared identity the closer is unrecoverable, and the phrase and the close arrive
  as two different deliveries; the floor may show *that* a thread converged and never *who* closed
  it. *(C)* **The escalation flare is a WON'T-DO** — its claimed observable, a gated / USER-ACTION
  post, is barred by protocol from coordination threads (the banner is chat-output only), so
  `needs_human` is deleted rather than carried permanently false, with the closure act named as a
  protocol change. Also settled: the family gets **its own endpoint, HMAC auth (a third auth mode)
  and validation order**, because nine of the batch envelope's invariants are false for a webhook
  delivery and admitting it would weaken that path for the reporter too; **no post body ever
  transits**; and one producer serves both consumers, the `coord.*` family and D2 § 4.9's tier-2 task
  title.
  ⭐ **A review round then found the same defect SHAPE at three sites, and fixing the shape is most
  of what changed: a rule derived over one population, applied to a second without re-deriving it.**
  *(1)* § 18.3's derivability test excluded *"the bridge's configuration, which this producer cannot
  see"* and never asked the same question of the **coordination repo's own** config, equally
  invisible — so four fields reached for it in four unstated places. § 18.3.1 is the consolidation,
  and two of the four dissolved: `carrier` reads the title's bracketed token **syntactically** with no
  set consulted (measured: all 728 real titles carry one, and the six distinct tokens are exactly the
  six configured), and `participants` stops expanding `all` because `targets` already does it once.
  What is left is **one declared input** provisioned with the hook registration — `roster[]` and
  `shared_identity` — carrying the config revision it was copied at, and **guarded**, since a
  body-derived name absent from the copy reds `coord_roster_unknown_name` on that seat's first post.
  *(2)* § 7.3's redaction rules are derived over **argv** and § 18.10 claimed to reuse them
  *unchanged*, *"covered for free"* by § 7.5's fixtures. Both false: measured over all **728** real
  coordination titles, the pass corrupts **7.0%** of them, **49 of the 51 hits being rule 4's
  bare-whitespace separator firing on English** (`token that` → `token ‹redacted›`) — and § 7.5 could
  not have caught it, because all 13 fixtures were `(tool, command-or-path)` pairs. § 7.3 now declares
  two **profiles** differing in exactly one rule (`coord.subject` requires an explicit `:`/`=`), which
  takes the damage to **0.7%** while still redacting every credential shape planted in a title;
  narrowing rule 4 for *all* callers was refused because it deletes what fixture 9 holds. § 7.5 gains
  **fixtures 14–17** and a **caller** column, and AT-2 gains a fourth RED that fails in **both**
  directions. *(3)* Decision 45's no-rate-limit argument is sound only for deliveries that **pass**
  step 3; a request failing the HMAC is by construction not GitHub's, so § 12.3's failed-auth limit is
  reused for it and `coord_signature_invalid` counts a refusal that previously left no trace.
  ⛔ **`converges` is deleted, because it could not make the distinction it existed to make.** Measured
  over the coordination repository's **entire** comment population — 10,552 comments, with a positive
  and a negative control — the *"zero open questions"* phrase is on **78.1%** of posts: it is a
  per-post sign-off, so the flag was true **11.5** times per closed thread and `converges + closed`
  collapsed to `closed`. The protocol's `[CLOSE]` token is on **2.12%** of posts, **37.8%** of closed
  threads and **0.6%** of open ones — so `declares_close` keys on that, named for what the post
  declares rather than for a thread property it cannot establish. It **gains** a fact finding B looked
  to have lost: all 224 anchored `[CLOSE]` comments carry a `FROM:` line, so the closer is nameable
  where `issues.closed` never names one. **Thread-level convergence is a QUORUM and is now declared
  NON-DERIVABLE** — no observer-CC flag, no required-participant set, no ACK ledger — rather than
  approximated. **AT-26 asserts the RATE over a replayed corpus**, because the defect is statistical
  and every per-post assertion passes under both implementations.
  ⚠ **Two claims this entry itself made are corrected.** *(a)* It said the obligations D2 and D3
  inherit were *"recorded as requests"*; **no request was recorded anywhere in the diff**, and § 18.11
  said the opposite in terms. There is still **no D2 or D3 edit** — what those documents inherit is
  cited at its site and carried in § 18.13, and the join key is ruled on `card#7957`. *(b)* § 18.8
  step 7 claimed the idempotency key *"cannot expire into a double-derivation"*. True of the
  constraint's form, false of any store: the reporter's equivalent is bounded by § 11.3's 8-day spool
  residency and **GitHub's Redeliver has no such floor**, so an old redelivery re-derives. Marked
  `D2-CITED:` at its site — a gate that structurally could not see the class now can — with the
  residual in § 18.13, and **steps 7 and 9 now commit together**, so a failed derivation leaves no
  digest to absorb the operator's retry. Also corrected: `to` falls back to labels on a **measured 30.1%**
  of posts, not the 15.7% a 300-comment sample suggested; the § 18.3 prefix row no longer restates a
  set its own source says *"do not restate"*; `lifecycle`'s unreachable unrecognised-value clause is
  gone; AT-24's fixture no longer wants two `issues.opened` on one thread and AT-25's no longer
  depends on a config switch that is **off** here. New overall: **AT-26**, **decisions 46–48**,
  **§ 18.3.1** and **§ 18.8.1**, eight § 14 rows, three § 16 build-order rows, four § 7.5 fixtures.
  ⛔ **The route counters — ten then, eleven now — are declared in § 18.8.1 and deliberately NOT
  enrolled in § 12.7**:
  membership there is an obligation on the store's counter plane, and enrolling them makes
  `verify-fleet-state.py` red once per counter — which is how the obligation was found, and it is
  filed in § 18.13 rather than papered over.
  ⛔ **A second review round then found three rationales that were false about their own sources, and
  all three are re-derived rather than re-worded.** *(1)* **`participants` no longer reads labels
  ALONE at `closed`/`reopened`** — its justification, *labels are more current than the body*, is
  false at source, because the integrity Action **materialises** missing labels from the body's
  `FROM:`/`TO:` lines and the `closed` delivery carries the body it has at close, already listed in
  § 18.13 row 1 among the keys read. The cost was concrete: a thread whose labels had not arrived
  emitted `participants: []` at its close, **losing both endpoints of the thread line at the moment
  the floor renders it**, while `issue.body` in that same delivery named them. **AT-25 gained a fourth
  RED at the `closed` moment** so the rule has a check that fails rather than a paragraph that
  asserts. ⛔⛤ **The repair that round shipped — body-only, labels supplying no member at any moment —
  is SUPERSEDED by the round-4 entry below, and its stated premise was FALSE.** It is described here
  as it was, so the correction below has something to correct, and every claim it rests on is
  withdrawn there by name. *(2)* **§ 18 never said what the hook
  SUBSCRIBES to**, which is the root cause of § 18.13 row 5 claiming the `issue_comment.edited`
  deferral was un-backfillable *because the hook was not subscribed* while pricing the fix, in the
  same cell, as *"one action in step 8"* — a subscription problem and a derive-set problem at once.
  § 18.8 now declares the subscription (**two whole events, every action**: `issues` and
  `issue_comment`) as the deliberately wider set, with step 8 as the narrower derive set, written
  granularity-independently so no unread claim about GitHub's registration model is load-bearing. It
  also decides the default riding on it: **a step-8-ignored delivery commits NO digest**, so a later
  widening is backfillable from GitHub's delivery list instead of being absorbed as a duplicate — and
  row 5 is re-derived from those two facts, bounded honestly by a vendor retention nothing here can
  read. *(3)* **The step-3 rate-limit bucket is keyed `(route, source IP)`**, a bucket of this route's
  own. § 12.3's limit was priced over **seats sharing with seats**; this endpoint is unauthenticated,
  so a shared bucket would have let anyone on the internet spend the refusal budget only a bad-token
  holder could previously reach — decision 45's *"the same trade § 12.3 already accepted"* was
  understating a population change, which is the round's own defect shape a fourth time. The
  distributed-source residual a per-IP key cannot cover is now named rather than implied. Also
  corrected, none of it load-bearing: AT-2's first RED said *"all 17 fail"* where fixture 14 passes
  under an identity sanitizer (the fourth RED covers it, and § 7.5's own preamble said the same thing
  wrongly); `coord_name_malformed` had **three disagreeing definitions** and now has one, at § 18.8.1,
  covering both callers and both causes with absence distinguished from malformation; § 18.13's
  *"`posted_at` is `null` on a `coord.thread`"* is scoped to `closed`/`reopened`; § 15's *"rows 46–48
  are all one shape"* enumerated 47, 48 and **45**; and the one 8-character source pin in a document
  that uses seven is gone with the sentence that carried it.
  ⛔⛤ **A third review round then REFUTED the second round's own repair at source, and `participants`
  is now the UNION of the body-derived names and the `to:`/`from:` labels.** ⚠ **This paragraph
  supersedes a claim that is published in an uneditable place** — the commit message of `e2b0be1`, and
  the paragraph above it — that *"a `to:` label naming anyone the body's `TO:` line does not is a
  structural hard-fail … so the label set is a **subset** of the body-derived set by construction and
  reading labels can only lose names."* ⛔ **That claim is FALSE and inverted, and this bullet is where
  it is withdrawn**, because a commit message cannot be corrected in place and a reader who finds it
  first must be able to find this. Three mechanisms, each read at `e9bc22a`: the integrity workflow
  subscribes `issues: [opened, edited]`, `issue_comment: [created, edited]` and
  `pull_request_target: [opened, edited]` and has **no `labeled` trigger**, so a label added to a live
  thread is validated by nothing; the hard-fail **detects and repairs nothing** — the script's only
  label mutation on any path is `addLabels`, and it removes none; and `DESIGNS/protocol-spec.md`
  § Recipient addressing states that **membership is label-authoritative** and that widening it *is* a
  deliberate label edit, which the gate's own remediation text repeats. **A label-widened thread is
  the protocol's PRESCRIBED way to widen membership, not a violation nobody has caught yet.**
  ⭐ **Measured over the whole live population 2026-08-28 — 729 non-PR issues: 36 (4.9%) whose labels
  name someone the body does not, and ZERO in the opposite direction** — so the body-only rule was not
  merely unproven, it was strictly **lossier** than the rule it replaced, on 36 real threads. Issue
  `#635` is the worked case and is now AT-25's fifth-RED fixture: body `FROM: pm` / `TO: magento`,
  labels `from:pm, to:pm, to:magento, to:platform, to:moodle` — body-only drops `platform` and
  `moodle` while the delivery carries both. **Documenting it as a known miss was explicitly refused**;
  it would ship a published field knowingly wrong 4.9% of the time and rename the defect as
  documentation. ⭐ **AT-25 gains a FIFTH RED because the fourth could not catch this**: the fourth
  pins *"do not read labels alone"*, so it passes under every body-reading rule including the body-only
  one — which is how the defect shipped past five green gates. § 18.5's *"the protocol names that
  source authoritative"* is scoped to **addressing**, where it is true, and away from **membership**,
  where it contradicted the spec sentence § 18.5 itself quotes. `coord_participants_unlabelled` is
  kept — it guards the no-source-at-all boundary — but its live population is **0 of 729** and it is
  no longer cited as the counter that would have caught this; the **new `coord_participants_label_only`**
  counts the union's disagreement case, with a known expectation of 36 of 729. Also corrected in the
  same pass: the *"14.6 times per closed thread"* ratio divided two different populations and the
  honest same-population figure is **11.5** (6,526 phrase-carrying posts on closed threads ÷ 568
  closed threads, 2026-08-28); § 18.6 re-minted finding A's **641** as *"unlabelled deliveries"* when
  it is a count of `issues.labeled` **deliveries** on another system's stream (`CoordinationClassifier`
  at `f85b419`) and **0 of 729 live issues lack an addressing label**; § 18.13's *"two of these nine
  are closed by one act"* is **two fully and one partly**, since three rows name a capture; § 12.3's
  failed-auth limit is keyed `(route, source IP)` to match the bucket § 18.8 actually reuses; and
  § 4.1's endpoint table now lists `/api/ingest/github` by pointer.
- **card#7976** — **the acceptance suite leaked one live flusher daemon per run, and the mechanism
  was not the one the card described.** `Seat.freeze_flusher` writes `flusher.lock` so a hook
  observes a live owner (§ 2.3) instead of forking a real flusher into an exact-count assertion —
  legitimate precisely because it uses the product's own liveness rule and disables nothing. But
  that rule is `now() - lock.mtimeMs < LOCK_STALE_MS`, `now()` is whatever `FLEET_REPORTER_NOW_MS`
  says, and the freeze used **wall time**. So the one hook the spool-overflow entry directly below
  this one pinned **two hours ahead**
  (§ 4's aged-out drop, `of_at + 2 h`) read a just-written lock as two hours dead, concluded
  correctly by § 2.3 that no flusher was alive, and forked a real detached one with no exit
  condition — **within 35 s of the run starting, not after the 90 s expiry the card described.**
  Measured at `cbc2a6a`: a full 163 s run took the box from 12 live daemons to 13, and the one it
  added carried `FLEET_REPORTER_CONFIG=…/overflow-aged/config.json` with
  `FLEET_REPORTER_NOW_MS` 54 minutes ahead of wall time.
  ⚠ **Two corrections to the filed report, both measured rather than argued.** *(1)* The 90 s
  expiry is real but **latent**: no seat here is driven late enough on a real clock to age out of
  its own freeze, and a run in which it fired would have leaked more than the single daemon
  observed. *(2)* The dozen daemons accumulated on the reporting workstation were **not this
  suite's** — their configs are `/tmp/tmp.*`, `/tmp/mezzanine-roundtrip-*`, `/tmp/rtprobe-*` and
  two scratchpad paths, while every seat here lives under a per-run `/tmp/fr-suite-*`. They came
  from ad-hoc probe rigs, which is a separate leak with a separate owner.
  ⛔ **So the obvious fix — re-freeze before every hook — was rejected on evidence rather than on
  the docstring's disclaimer**: re-freezing on wall time leaves a pinned invocation exactly as
  stale, and would have fixed none of the leak that was actually happening.
  **The freeze is instead pinned to the clock the invocation will read**, in `Seat.env()` — the
  one place that both decides `FLEET_REPORTER_NOW_MS` and is reached by every invocation,
  including the AT-10 and AT-16 writer bursts that build their hooks in generated source and never
  call `hook()`. The lock is then 0 s old under whatever clock that hook uses, which is the state
  the freeze always modelled and never achieved when pinned; `flush()` opts out
  (`env(freeze=False)`) because it *is* the flusher and must find no lock.
  ⚠ **Reached by every invocation, but re-applied per `env()` CALL — and those two coincide
  everywhere except the writer bursts.** AT-10 and AT-16 call `env()` once per worker `Popen` and
  the worker then drives 40 (resp. 15) hooks off that one freeze, so for those two paths the
  window is **narrowed, not closed**, and the "0 s old" property does not extend to their hooks
  2..N. Measured under the bursts' own concurrency rather than extrapolated from the idle p99:
  the lock's age at the last hook is **22.6 s** (AT-10) and **6.5 s** (AT-16) against
  `LOCK_STALE_MS` 90 s — a **4x** margin, and AT-10 would have to grow ~4x, to ~159 hooks per
  worker, before a hook in it read its own lock as stale. Left open deliberately: closing it
  requires a second freeze implementation inside generated worker source which could only
  re-stamp the lock on *wall* time — the exact defect fixed above — and would be silently wrong
  the day a burst is given a pinned clock. § 17 is the guard, and it fails loudly.
  ⭐ **The freeze is prevention, and prevention that fails is silent — so the run now also
  MEASURES.** A new § 17 sweeps `/proc` for flusher daemons whose config lies under this run's own
  temp directory, **fails** the suite naming each one, then reaps them; scoping identity to the
  run's `mkdtemp` is what makes reaping safe beside another checkout's daemons or a concurrent run
  of this same file. **A control daemon is planted first** — a wall-clock freeze plus a hook pinned
  two hours ahead, the defect verbatim — and the sweep must find it and then see it gone, because a
  sweep that passes by finding nothing is worth nothing until it has found something. Where
  `/proc` cannot be read the sweep prints `SKIP` and is reprinted as *not measured*, never as a
  pass. Shown to discriminate: with the clock-correct freeze reverted the run reds four checks and
  names both leaked daemons by config path.
  **CI was not accumulating these.** All six workflows are GitHub-hosted `ubuntu-latest`, whose
  per-job VM is discarded, so nothing carries across runs — this was workstation hygiene. One
  in-job consequence was real, though: `fleet-reporter.yml` runs the suite twice in one job and the
  second step is the latency gate, whose own comment has it running "on an otherwise-idle job". A
  daemon leaked by the first step was alive throughout the second, so that premise did not hold;
  the end-of-run sweep restores it. ⚠ **No claim is made that these daemons affected any
  measurement** — they idle at 0% CPU and their contribution has never been measured.

- **card#7965** — **the ratified floor-preview is a reference implementation of a subset of D3 and
  never said which subset, so an absent render surface was indistinguishable from one that was
  missed.** The artifact is what card#7341 builds FROM, and the reader who needed that answer is
  that card's implementer. Three surfaces already said the artifact was *partial*; none said *of
  what*, and `docs/design/floor-preview/README.md`'s *"encodes, as working code, every design
  ruling of the 2026-08-26/27 operator sessions"* — true of the operator's **design** rulings —
  reads as coverage of D3's **render surfaces**. **That conflation was the defect, not the
  absence.** ⛔ **The alternative was declined on the product, not on cost:** completing the preview
  against D3 would make it a second full implementation of D3's render surfaces — the floor built
  twice — and card#7341 a port of it rather than the build. There is one build. So the artifact now
  carries **one machine-readable scope table**, a row per membership-tested render surface `FLOOR.md`
  § 5.4 publishes (the same six `verify-floor.py` **G7** closes over): *implemented*, naming the
  render table it is implemented by; *partial*, for `link_state`, which renders **raw** in two
  drill-down blocks and is **not** membership-tested; or *not implemented*, naming why in one clause
  (§ 7.2's eighteen badges, § 7.6's `activity_state`). It is rendered on the page from that same
  table rather than re-typed, and the membership test itself is **derived** from it, so a row is the
  one place a surface's status is written. ⭐ **A declaration with no check is a comment, and this is
  the case where that bites**, so `floor-preview.selftest.mjs` re-derives § 5.4's six from the
  document — from **both** of § 5.4's enumerations and its own count in words, cross-checked — and
  set-differences them against the table **in both directions**: a surface D3 gains with no row reds,
  and a row for a surface D3 does not publish reds. **D3 gaining a seventh surface now reds the gate
  instead of passing silently**, and each direction has its own anchored mutation control, one of
  them a D3 that *grew* a surface rather than one that broke.
  **Three defects in what the artifact does implement, all in the same class and none reachable by
  the member-set primitive card#7943 landed — they are keyed on IDENTITY, not on an enum member.**
  (1) A seat's `desk` against the floor map: the SVG iterated a **fixed four-slot list** and
  silently **dropped** any seat outside it, while the overlay pass read `THEMES[inst].desks[s.desk].x`
  and **threw the whole render away** — one fact derived twice, failing in opposite directions on the
  same seat. § 3.2's overflow rule and § 9 **F13** state the behaviour that is neither: the surplus
  seats render in a **labelled overflow row** with the same desk and the same render, under a
  persistent notice reading *floor map is short N desks*, and F13's *Never* column is one phrase —
  **dropping a seat**. Placement is now **one pure function** of (map, seat list) that both the SVG
  and the overlay pass read, and the map's slot count `S` comes from the map. (2) Three unguarded
  `THEMES[…]` lookups threw on an install the client's art has never seen; all three now resolve
  through one function, and the unthemed floor declares **no slots**, so § 3.2's overflow rule is
  already the whole answer for it — every seat of an unknown install renders, labelled. (3)
  `subagents[].subagent_type` was defaulted to *"intern"*; § 5.6 says the type tag is **not drawn**,
  so it is not, and a substitute stated a fact the wire never sent. ⛔ **`title || "untitled"` beside
  it was NOT changed and must not be**: § 5.1 gives a null title the literal *untitled* and AT-D3-4's
  first RED is falling back to `subagent_type`, the tool name or *"subagent"* — the two nulls are
  different rules, and treating them alike would break the compliant render to fix its neighbour.
  Both are now one function with a control per direction. **The sample fleet carries a seat with no
  slot on the `sola` floor and interns for both null edges**, because a behaviour no sample reaches
  is one no implementer sees — the artifact is where card#7341 reads what these render like. The
  gate carries a planted mutation for every layer, and **how many is counted at run time and printed
  on the run's own last line** rather than restated in the files that describe it.
  ⭐ **And the overflow row was drawn INSIDE the floor — on top of sola's tea bar — while every
  check above passed. D3 § 3.2 had already said where it goes: BELOW THE FLOOR.** Four passes read
  § 9 **F13**'s summary row, which names the row and its notice but not its position, and § 3.2 at
  `FLOOR.md:537` is what owns the position. Drawn inside the floor, the row needed machinery to be
  safe — a per-theme `overflow:{x,y}` origin, a declared "clear run" between the tea bar and the
  plant, a stated row capacity, and a pairwise desk-versus-furniture collision gate over an
  arbitrary-shape bbox extractor. **All of it is deleted, because the question stops being asked**:
  an overflowing floor is now drawn on a canvas one band taller and the row lives in that appended
  strip, so the floor's shapes stop at `FH`, the band's start there, and collision is impossible by
  construction **at any row length**. What replaces the collision gate is one invariant with one
  scalar per floor in each direction — **nothing the floor emits reaches below `FH`, and nothing the
  band emits rises above it** — with a planted-mutation control for each leg (furniture pushed below
  the line; the band raised back up into the floor), each seen red first. ⛔ **And an unmeasurable
  shape now makes that assertion FAIL.** The deleted layer guaranteed *unmeasurable is loud* and the
  guarantee was false in four demonstrated forms — an arc command, a `<polygon>`, a `NaN` width and
  an unmodelled `stroke-width` — three of which stayed **green** with an obstacle drawn on the desk,
  because each fell through a silent `else` and left a shorter list of boxes that still looked
  clean. Every element is classified now, a tag the measurement does not own is named, and the
  answer is *not measured* rather than a number. Two of the four are now MEASURED instead — an arc
  is bounded by ±2r about its endpoints, a stroke pads its shape by half its width — and the other
  two are planted controls, beside the two generalisations of the same class (a path command the
  measurement does not implement, a transform it does not model). ⚠
  **Still not evidence about the picture**: this is arithmetic on the artifact's own emitted
  coordinates, so z-order, opacity and the difference between a box and the ink in it are outside
  it, and where a bound cannot be computed exactly it is computed **wide** so that its only possible
  error is over-reporting.
  ⭐ **AND THAT LAST SENTENCE IS WHY THE ROW MOVED AND COLLIDED AGAIN — this bullet's own fix was
  the fourth in one class, and the fifth round stopped patching it.** With the band correctly below
  the floor, the sample seat's thought bubble landed **on the band's own arithmetic line** (the slot
  and seat counts, behind an opaque white bubble) and its nameplate hung **out of the strip**, chip
  and command line over the lobby. Every gate above was green, and the root cause is structural, not
  arithmetic: **the artifact has two coordinate systems** — SVG user units, and HTML overlays
  (`.bub`, `.plate`, `.mk`, `.hit`) positioned over that SVG but **sized in CSS pixels** — and
  **every gate ever built here measured only the SVG layer**, so each round moved SVG content
  correctly and re-collided with a layer in no denominator. § 8's own overlay leg made the same
  mistake one level down: it compared the bubble's anchor against `FH`, which is where the strip
  *begins* — and the strip begins with the header the row must not cover.
  **The upstream fix is `tools/design/floor-preview.browser.mjs`**: assert geometry from
  `getBoundingClientRect()` in a real headless browser, where both layers share ONE coordinate
  space, so *does the bubble cover the header* is a rect intersection between things that were
  actually laid out — **no transform parser, no arc bounding, no stroke padding and no *not
  measured* category**, none of which is a question once the browser has done the layout. It asserts
  its client↔user-unit map against a measured element before deriving any region through it; that
  **no overlay covers any line of the band's header**, measured with the browser's own glyph widths;
  that **every overlay is inside the storey region its own `data-band` declares** — the artifact
  now declares that, and the gate checks the declaration instead of believing it; and that **every
  child of `#world` is either judged or a declared animation affordance**, so an untagged overlay
  class reds rather than sitting outside every check. Its population is two room widths × three
  framings, and **widths are the only axis that can falsify anything**: `#world` scales the SVG and
  the overlays together, so an overlay's user-unit size depends on the room's width alone — measured
  across all six cases, not assumed. **No browser is a FAILURE, never a skip.** Its controls re-mint
  head `fe482eb` whole — both constants at the values that shipped — and require both defects to go
  red on their own messages, beside a floor-side containment defect, a lying `data-band` and an
  untagged overlay. The two instances are fixed by giving the band a budget for the **whole overlay
  stack**: the desks move to `FH+300` (the bubble's anchor clears the header's own deepest bound by
  40 user units) and the strip to `520` (the nameplate is `53.8` CSS px, so the strip must cover
  `300 + 64 + 53.8·BW/room`, which carries down to a 655 px room — derived by the gate, not stored).
  § 8's static invariant is **kept** as the cheap no-browser backstop, with its overlay leg now
  held against the header's re-derived extent and a control planted at the value the old `>= FH`
  leg passed. ⚠ The browser gate measures **boxes, not ink**: opacity, z-order and legibility are
  outside it, and opening the file remains the last word on how it looks.
  Two adjacent limits are **stated rather than left to be inferred**: `deskSVG` hangs the intern
  tray *outside* the slot, so at n ≥ 2 a seat with subagents reaches into the next one, and past
  roughly n = 12 the clamped slab is narrower than the character drawn on it — both are `deskSVG`
  re-layouts and belong to card#7341's build; and `placeFloor` is **order-dependent**, so it is a
  pure function of the seat *list* and not of the seat *set*
  § 3.2 asks for — § 3.2's own answer is the slot function `h(seat)` with forward probing, which
  this preview does not implement and card#7341 does. **A gate hole in the same class was closed in
  the gate itself, at every site that had it**: an `indexOf` result was used as a **slice bound**,
  and `indexOf` answers `-1` for a heading that has moved — `slice(i, -1)` is *the document minus
  one character*, not *to the end*. A § 5.4 whose closing anchor had moved silently widened the
  population to the rest of the document, where six surface names are still findable, and the gate
  printed `ok parsed 6 render surfaces from § 5.4` over a § 5.4 that published none — **the
  false-clean shape this whole deliverable exists to prevent, inside it.** Fixing that site alone
  left the others, and renaming § 7.1's closing anchor `### 7.2 Badges` widened its slice to a
  quarter of the document with the ten `render_state` members still parsing out of it, so the gate
  stayed PASS. All of them go through **one `sliceBetween(md, open, close)`** that returns `null`
  when either anchor is missing, every caller treats `null` as a failure rather than an
  empty-but-clean parse, and one control renames each closing anchor in turn and **prints** what the
  replaced shape would have widened to. ⚠ **The widths are not written down here, and that is the
  point rather than an omission**: an earlier revision of this bullet and of the primitive's own
  doc-comment each carried one, and both were stale within two commits — a figure restated inside
  the argument that restated figures drift is that argument demonstrating itself. The run prints
  them. § 5.5's notice is asserted **in full** against the
  re-derived shortfall rather than by its opening words, which left the count and the noun free to
  drift and stay green.

- **card#7952** — **the spool-overflow check read a wall clock it never meant to depend on, and reds
  on CORRECT behaviour when a run straddles a top-of-hour by more than the grace below.**
  `… and drops nothing while deferring`
  asserts that a hook over the 2 KiB bound refuses to unlink the live bucket. That refusal is
  **hour-keyed**: `enforceSpoolBoundFromHook` defers while the oldest bucket is the current-hour
  bucket, and once that hour has ended plus `BUCKET_GRACE_MS` the same hook **drops it, correctly**
  (§ 11.3). The 20 hooks were driven off the real clock and take **~5.0 s measured** — the same order
  as that **5 s** grace — so a run crossing the roll watched the reporter do exactly the right thing
  and failed the assertion for it. Reproduced deterministically under a pinned clock: a simulated
  crossing that ends past the grace drops the pre-roll bucket (`spool_dropped_events=5`), and pinning
  the roll to just after the first hook reproduces the **exact reported `expected 0, got 1`**; the
  same crossing held *inside* the grace, and a pinned run that does not cross at all, both stay green
  — so it is the crossing, not the pinning. **The contention it was blamed on is real but is the
  amplifier, not the cause:** no drop path in the reporter is reachable without the oldest bucket's
  hour having ended, so load can only red this check by stretching the block past the 5 s grace —
  and a ~5.0 s block against a 5 s grace has essentially no margin to lose. ⛔ **Not fixed with a
  retry and not by loosening the assertion**: the assertion was right and the reporter was right, and
  the harness was the wrong side. The 20 hooks are now pinned 30 minutes into the previous UTC hour,
  where no run duration — loaded, slow disk or otherwise — can reach a boundary. ⭐ **The pin also
  turns the accident into coverage.** The hook-side aged-out drop, § 11.3's other half, was executed
  only when a run happened to straddle the roll — the flake *was* its only exercise — and is now a
  stated check that one hook two hours on drops the bucket and counts **all 20** lines. Both arms are
  shown to discriminate: with the hour guards planted out the deferral assertion reds (`dropped 20
  while deferring`), and with the drop planted out the complement reds — **the latter being a defect
  the block could not previously catch at all**, because a reporter that never drops satisfied every
  assertion it contained.

- **card#7943** — **the ratified floor-preview carried SIX copies of one enum's member set, four of
  them covering only the four `render_state` members its frozen sample fleet happens to contain.**
  `FLOOR.md` § 7.1 defines **ten**, and § 5.4 / § 9 F9 / AT-D3-11 require an eleventh case — a
  member the client does not know — to render as **explicitly unrecognised, carrying its raw
  string**, never mapped to the nearest known member and never a crash. `GL`, `SCREENC`, `POSE`
  and `labelFor`'s switch each broke that, and **the loud one was the least of them**:
  `GL[s.render_state]` destructured `undefined` and threw a TypeError that
  killed the whole render, while `SCREENC` emitted `fill="undefined"` into the SVG, `POSE` drew a
  default creature and `labelFor`'s switch fell through to `undefined` — three SILENT wrong renders
  that look like a render and report nothing. ⭐ **A FIFTH site was found by re-reading the
  subsystem rather than by grepping the field name, and it was silent too:** the lobby's per-floor
  summary iterated § 7.1's fixed member order, which over a wire value is a **filter** — a seat in
  an unrecognised state fell straight out of its own floor's count (four seats at the desks, three
  on the line), which is AT-D3-15's *the lobby never invents a count* failing in the direction
  nobody watches — **and it was MASKED by the crash above.** The desk loop runs over every floor
  before the lobby does, so at the pre-fix revision an unrecognised seat anywhere threw out of
  `GL[…]` and no lobby line was ever drawn to be wrong; removing the crash is what makes the
  undercount the live failure mode. It had to land in the same change for exactly that reason: a
  fix that converts a loud failure into a silent one is not a fix.
  **The repair is one table and six derivations, not six patched call-sites**: one
  row per § 7.1 member in § 7.1's own order, carrying the desk render, pose, marker, chip class,
  monitor tint, currency and label line as cells, and one explicitly unrecognised row beside it.
  The member set is now written **once**; patching the sites independently is what minted six
  copies of it in the first place. **Reachability, stated rather than implied:** unreachable today — the artifact ships a
  fixed sample fleet — and reachable the moment card#7341 feeds it a live wire, where D2 can
  deliver any of the ten. ⛔ **The unrecognised desk keeps its character and is drawn NOT-CURRENT.**
  An empty chair is the render of `stale` and `offline` (§ 7.1), so drawing one for a member nobody
  recognises would be the nearest-member guess arriving through the desk instead of through the
  chip; the character is the seat's identity redrawn, which § 5.4 admits explicitly and which says
  nothing about state. ⭐ **`api_error_type` gets the same treatment, because § 5.4 gives it the
  same rule** — *"rendered verbatim **and** membership-tested… Reading the two rules as
  alternatives is what would make one of them dead"* — and § 5.2's drill-down table has no row for
  it, so the desk line is its only surface. § 7.6's twelve now render as **the raw value with the
  published phrase beside it**, a thirteenth renders with the **unrecognised** marker, and an
  unrecognised value in *any* membership-tested field the desk draws — not only `render_state` —
  makes the desk not-current. Without that, AT-D3-11's second RED would have arrived through a
  different field in the release that fixed it for the first. **⚠ Reported to D3's owner, not
  settled here:** § 7.1's Label line cell writes the example as *API error — rate limit*, eliding
  the raw value that § 5.4, § 5.1 (*rendered verbatim*) and § 7.6's column heading (*The line
  beside the raw value*) all require. Two rule statements and a heading outrank one abbreviated
  example.
  **Four label cells were not § 7.1's Label line and three of the four were visible on the
  ratified artifact**, carried in from the old switch: `idle` dropped ***finished — ***, which is
  the half that makes it a *positive observation* rather than the silence § 7.5 refuses; `blocked`
  dropped ***(seat clock)***; `stale` and `offline` wrote *— 11m* where § 7.1 writes *— **no data
  for** 11m*. Consolidating six copies into one primitive is where the primitive owes being right.
  **Visible changes to the ratified picture, and only these:** the `stale` sample desk gains the
  not-current treatment § 7.1 and § 7.3 already specified for it (*empty chair, desk dimmed*), and
  four sample desk labels gain the § 7.1 text they were missing. The floor SVG is otherwise
  byte-identical.
  **The check is `tools/design/floor-preview.selftest.mjs`** — Node, no dependencies, no network.
  It re-derives **three** published tables from `FLOOR.md` and compares them **cell by cell**:
  § 7.1's ten members *with their Label line column*, § 7.1's seven `unknown_reason` sentences,
  and § 7.6's twelve `api_error_type` phrases — so a member added to D3 with no row reds it, and
  so does a rendered string that has drifted from the document's. ⛔ **The first revision of this
  suite parsed only the member NAMES while three surfaces claimed it checked the sentences**; a
  sentence rewritten to say the opposite of D3 ran green. Fixed, and the fix is proved by
  mutation. **Seen to fail**: 38 checks red against the pre-fix artifact, plus five anchored
  mutations — the pre-fix lookup, the lobby filter, a rewritten sentence, a rewritten phrase, and
  a label cell reverted — each required to go red on its own, because a check that only ever ran
  behind a crash, or only ever ran green, is a check nobody has seen work.

- **card#7936** — **A17's clock had two setters and its accessible text was bound to one of them, so
  the hands and the text could render the same minute differently.** `FLOOR.md` § 6.2 A17
  constraint 5 read *set by the same A17 firing that moves the hands and by nothing else* — but
  constraint 4 and § 6.5 give the hands **two** setters: the firing, and the render that establishes
  or re-establishes a LIVE feed, which moves the hands and writes **no** animation-log row (§ 11: *a
  firing writes a row, where the setting of § 6.5 writes none*). *By nothing else* excluded the
  second. A client built from that sentence draws the **connect snapshot** with hands on the current
  minute and an accessible name never set, and a **successful reconnect** with hands on the new
  minute and an accessible name still holding the minute from before the disconnect — **one fact,
  two renderings, disagreeing**, which is the *exactly once* premise of that same constraint and
  § 2.4's one-form-per-fact rule broken by the sentence invoking them. It is worst on the path A17
  exists to make visible: a reconnect onto a feed that then dies (§ 9 F1) freezes the hands at the
  reconnect minute and the text at an **older** one, so a screen-reader user is told a *different*
  wrong time from the one on the wall. **The repair is to bind the text to the hands rather than to
  a named driver** — *set in the same render that sets the hands*, with constraint 4 left owning
  which renders those are — so the coupling survives the driver set moving again, and no fourth
  phrasing of the set-vs-fire distinction is minted. ⭐ **The unset case is stated for the first
  time:** § 6.5 draws a never-live clock **unset**, while *the minute and nothing else* said nothing
  about a clock with no minute; the text is now decision 13's ***not reported***, never an empty
  string (an element with no accessible name is a clock assistive technology cannot find) and never
  a plausible time. **No gate caught any of this and one now does:** AT-D3-6's first read of the
  text fell inside the heartbeat phase, by which time a firing had set it either way, so the test
  passed on a defective client. It gains a GREEN that reads the text **before the first heartbeat**,
  where only the establishing render can have set it, and a **fifth RED** — the text driven by the
  firing alone — which passes every other assertion in the test and fails only that read.
  **Deliberately NOT changed, and reported instead:** § 2.5's 1 s-tick row (*advances on the
  heartbeat and on nothing else*) and § 5.5's row (*read at the moment a `feed.heartbeat` arrives*)
  are statements about what may **drive** motion, and § 6.5 is explicit that setting a value on
  first paint is not an animation of it — so both are true as scoped and neither is restated here.
  § 2.3's computed-values row was the one flatly false copy — *sampled when a `feed.heartbeat`
  arrives **and at no other moment*** is a universal negative § 6.5 contradicts — and it now points
  at constraint 4 rather than carrying its own spelling of the rule. **The reference artifact needed
  no change and is the evidence the property is buildable:** `floor-preview.html` already sets the
  `<title>` in `paintRoom()` on every path that moves the hands, already sets the room in
  `establishLive()`, and already renders unset as *no time set* — the implementation was right and
  the specification was wrong.

- **card#7897 (part 1)** — **the seat's task is a THOUGHT BUBBLE, and the bubble REPLACES the text
  chip rather than joining it.** `FLOOR.md` § 5.1 named the element *the task chip* in four places
  (§ 5.1, § 5.6, § 7.5 and AT-D3-14) while the operator-ratified reference artifact had already been
  drawing a bubble and no chip since 2026-08-26 — so the document and the artifact card#7341 builds
  FROM disagreed about the desk's single most visible element. The amendment is a **form** change on
  the test that decides one: the same five driving fields, the same honesty properties, **no D2
  change and no new § 6.2 row**, because nothing about the bubble moves. § 5.1 now carries the
  element's one statement — six rules — and every other site names it and points there. ⭐ **Three
  of the six are not restatements of the chip's rules.** *(1)* **One rendered form**: a chip
  surviving beside a bubble is one fact drawn twice (§ 2.4). *(2)* **A desk that draws no character
  draws no bubble** — the bubble is anchored to the character, and `stale`, `offline` and `retired`
  draw an empty chair or a cleared desk (§ 7.1). This is **the amendment's only truth-content cost
  and it is stated rather than hidden**: a dark desk that used to carry a chip now carries none, and
  the value is read in the drill-down under that panel's currency treatment — the fact is not lost,
  the desk's claim about a seat nobody can hear is. *(3)* **A title too long for its desk truncates
  with a MARK**, in a box sized from the measured text; a silently clipped title is read as the whole
  title, which is a claim the wire did not make. ⛔ **One directed behaviour is REFUSED and the
  refusal is recorded as decision 22 rather than left in a PR:** the ruling directs adoption of
  upstream's `0.15 s` fade-in → `1.2 s` linger → `0.3 s` fade-out state machine. Its linger is a
  timer (§ 6.3's second forbidden form), its fades are motion with no row, and the fade-out
  **collapses the null render** — once a bubble hides itself, *no bubble* stops meaning *`task` is
  null* and starts meaning *`task` is null **or** the linger expired*, and a null render two facts
  produce is not one. Upstream's machine is right for upstream: its bubble reports a **tool call**,
  an instant, where ours reports a **standing fact**. Its load-bearing half —
  re-show-swaps-text-without-re-fading, which exists to stop a busy seat's label strobing — survives
  for free, because a bubble that never fades cannot strobe. The overlap resolver and the
  measured-box sizing are adopted, as § 5.1 rules 5 and 4. **The reference artifact is corrected in
  the same change on two counts, both of which it was RENDERING:** its `.bub` carried a
  `4.5 s ease-in-out infinite` float — an un-held loop with no § 6.2 row, § 6.3's first bullet, the
  same defect shape as the `10 s` clock interval § 10.4 already refuses — and its character test was
  `render_state !== "stale"` **written twice**, once for the sprite and once for the bubble, so a
  sample seat in `offline` or `retired` would have drawn **both a character and a thought bubble on
  a desk § 7.1 says is an empty chair or cleared**. Both now select on one predicate,
  `hasCharacter()`, keyed on the member set. **Deliberately NOT fixed, and reported instead:** the
  artifact's `.glowpulse` — a `2.4 s` infinite pulse on every occupied desk's lamp, the meeting-room
  lamp and three status dots — is the same un-held-loop class, but it is ratified warmth on an
  operator-taste surface rather than this card's element, so it is surfaced for its own ruling.

- **card#7930** — **D1's harness-fact gate stopped being red for a reason that had nothing to do
  with D1, and D1's own re-capture obligation is discharged at 2.1.247.** `verify-harness-facts.py`
  pinned its ground truth to an absolute path ending in `2.1.240` — a build the installer
  garbage-collects — so it had emitted **31 failures** since the first harness update after it was
  written, **30 of them saying real hooks "are not declared in this build"**. A reader repairing D1
  to match that output would have deleted true facts, and *a gate that reds on correct work is a
  gate that gets ignored*. Three more build-specific literals were pinned beside it: the
  bundler-generated name `Ht` (regenerated every build), § 17's capture-run figures, and — the one
  that could have put a **false fact into the document** — global-first-match identifier
  resolution, which resolved `Notification.notification_type` to a 2-member set at 2.1.247 and to a
  **39-member list of shell dotfile names** at 2.1.243. It was right at 2.1.240 by luck. The build
  is now **READ from D1's five declared-build sites**, which must agree; the versions directory is
  derived from wherever `claude` on `PATH` resolves; identifier resolution is module-scoped and
  follows a bundled `import`/`export` pair to the declaring chunk; and when the declared build is
  absent the gate still reds — **fail-closed is the point** — but with ONE line naming the build,
  what is installed, and the fix. ⭐ **Newest-installed is deliberately not a default**: silently
  re-binding converts *"true of 2.1.240"* into *"true of today's box"*, which is the false clean
  the gate exists to prevent. ▶ **The re-capture then answered the card's real question:
  NOTHING D1 states about the harness moved** across `2.1.240 → 2.1.243 → 2.1.245 → 2.1.246 →
  2.1.247` — 0 of 22 fixture key sets, 0 of 9 enum value sets, 31 hooks, and every firing condition
  re-observed. **Two facts got stronger**: `PostCompact` and `SessionStart(source=compact)` were
  DOCS-CITED as *"not drivable"* and are now MEASURED (`claude -p --resume <sid> "/compact"` drives
  both), and `PostCompact`'s real key set is **exactly** what the stub declared — the first evidence
  this document has that a stub read from the binary is a fact and not a hope. **One thing is
  contested and deliberately left open**: three of three headless dispatch runs at 2.1.247 produced
  the **2.1.240** subagent lifecycle, not the 2.1.245 one D1 records — but that run varies *mode*
  as well as *version* (the 2.1.245 row was measured through a real TUI), so it settles nothing and
  **no fact was edited to prefer either reading**. Raw captures stay uncommitted per the 2026-08-23
  ruling; the rig was scoped with `--settings`, never by editing `~/.claude/settings.json`, because
  three other agents were live on this box. ⭐ **pm ruled the fork closed rather than open** — § 8.7
  already requires *both* lifecycles to be handled and AT-1 carries a GREEN case for each, so
  settling it would buy only the right to delete a trace the document must keep; **the primitive it
  exposed is fixed instead**: § 6.0 obligation 2 now names **MODE** (headless `claude -p` vs a real
  TUI) as a second axis beside VERSION, so *a fact measured in one mode is not established in the
  other* and the next re-capture records which mode a fact came from instead of re-opening that row
  at every build. Its **cost claim is corrected from estimate to measurement** in the same
  obligation — *"re-running it is minutes"* was **the argument the requirement rested on**, and the
  measured discharge was ten driven sessions, a fixture diff, ~25 restated version sites and
  propagation into the reporter; an obligation defended by an estimate an order of magnitude low is
  one that gets deferred, which is what happened across five builds. § 16 now **points at** that
  obligation instead of carrying its own copy of the trigger, and names the two levers that would
  make the next re-capture cheap.

- **card#7913** — **Gate 2 now runs over all of `resources/`, and Tiled's default layer encoding
  is refused rather than exempted.** Gate 2's argument became **universal** on 2026-08-27 (*every
  asset is a file Gate 1 can see*) while its enforcement stayed scoped to `resources/characters/`.
  So `resources/floor/` — the tree about to receive this project's **first vendored third-party
  art** (card#7341) — was covered by Gate 1 and by **neither Gate 2 clause**: a `.psd` there passed
  with a valid row, and a 40 KB base64 PNG pasted into a `.js` there had no path, no row and
  nothing to object. Both of those are now REDs in `bin/asset-provenance.selftest.py`, and both
  **passed** before this change. ⚠ **"Widen Gate 2" is a TWO-knob change** — the tree *and* the
  file-type allowlist — and moving one alone is not a widening: measured on a correct, CSV-only map
  set with zero base64 anywhere, widening only the tree puts **4 of 5 files RED**, not one of them
  for an embedded-bytes reason. Both knobs moved: clause 1 admits **`.tmx` / `.tmj` / `.tsx` /
  `.tsj`**, each with its reason in `FLOOR.md § 10.1`, **including the `.tsx` collision** (Tiled
  Tileset XML *and* TypeScript-JSX — harmless under `resources/` today, named so it is not a trap).
  ⭐ **New clause 3, and it is what lets clause 2's 1,024 B ceiling apply to the map formats with NO
  carve-out:** every Tiled artifact must store layer data **plainly as CSV** and **embed no tileset
  image**, read out of the artifact's own `encoding` / `compression` / `<image source=>` — no
  heuristic, no alphabet, no ceiling to tune. The embedded tileset image is the **true positive**:
  image bytes inside a map, with no path, therefore no row, therefore no provenance. Exempting
  `.tmj`/`.tmx` from clause 2 would have re-opened exactly that. ⭐ **And the reason it is CSV rather
  than a carve-out is a measurement, not a preference:** `looks_encoded()` is an AND over three
  character classes, so a run missing one passes clause 2 **at any length** — and Tiled's ordinary
  uncompressed base64 layer data (little-endian `uint32` GIDs) misses one routinely. Over a uniform
  1,200-tile map at every GID in `0..255` the run is **6,400 B in all 256 cases** and **154 of them
  pass clause 2**, including **GID 1, the first tile of the tileset**. A carve-out would have had to
  be reasoned against a verdict that flips when an artist repositions a tile. The selftest carries
  that coin as a pair — GID 1 evades clause 2 and clause 3 catches it anyway; GID 14 trips both.
  **The understated residue is corrected in the same change, on both surfaces that carried it:**
  `bin/asset-provenance.py` called the one-class evasion *"a deliberate shortcut nobody takes by
  accident"* — **ordinary machine output takes it by accident**, and that sentence was a live claim
  in a gate's stated-residue list that a reader draws a conclusion from. Narrowed to what is true,
  not deleted, in the module docstring, at the predicate, and now in `§ 10.1` clause 2, which had
  never stated the residue at all. **Selftest 43 → 58 fixtures**, all seen failing under three
  reverting mutations. **Out of scope, deliberately:** clause 1 still trusts the extension and
  sniffs no magic bytes (a separate question, recorded in the gate's docstring and § 10.1).
- **card#7912** — **the ratified floor-preview stops encoding three things the spec does not
  support.** `docs/design/floor-preview/floor-preview.html` is the artifact card#7341 is specified
  to build FROM, so a divergence in it propagates by design rather than by accident. **(1) `thinking`
  was an eleventh `render_state` in seven places** — a badge class, a `busyTop` test, the sample
  seat's own wire field, a colour map, a monitor branch, a glyph/label map and a drill-down branch —
  and the drill-down then mapped it **back** to `working` for display, which is both the tell that
  the author knew it was not a wire member and *precisely* the mapping `FLOOR.md` § 5.4 forbids by
  name. It is not a state: it is § 6.2 **A4**'s holding condition over a `working` seat
  (`render_state == "working"` ∧ `open_calls == 0` ∧ `open_turn == true`). The artifact now derives
  the **pose** from those three fields in one place (`poseOf`) and the drawing layer never sees a
  state name at all; the sample seat carries `render_state: "working"` with the two fields that
  select the pose, which is the data that should have driven it all along, and the drill-down shows
  `render_state` verbatim beside the condition. **The state chip renders the STATE** (§ 5.1: *pose
  and glyph | `render_state`*) — so the A4 seat's chip reads *WORKING* where it used to read
  *THINKING*, and the think pose is carried by the pose treatment it always belonged to: the `…`
  marker and the desk's state line. **D2 was not touched, deliberately**: the state set is closed and
  derived, and adding a member to carry a client-side pose would put a rendering concern on the wire.
  **(2) The lobby's per-floor summary iterated its own member list**; it now iterates § 7.1's fixed
  ten-member order, which § 4.1 requires — the same root cause, so the same fix. **(3) The wall clock
  and sky ran on a 10 s `setInterval` off the viewer's clock** — § 6.3's second forbidden form, and
  the mover that keeps moving after the feed dies. They now hold **one value**, set by a delivered
  `feed.heartbeat` and by nothing else (**A17**), so every other render — a zoom, an elevator ride, a
  sky repaint — draws the held value and cannot move it. The minute hand steps once a minute with no
  seconds term (**A17 constraint 1**), the hands' minute is carried as accessible text set on every
  path that moves the hands (**constraint 5**, and in the corrected form card#7936 will bring the
  document to), a live-establishing render **sets** the room while F1's 10 s poll sets nothing
  (**§ 6.5**), and a room that has never been live is drawn **unset** — hands hidden, flat sky —
  never at a plausible time. ⭐ **The artifact now demonstrates the argument instead of asserting
  it:** a *simulated feed* control switches between **live**, **down** and **never live**, and on
  *down* the clock holds while F1's poll count climbs beside a *polling (feed down)* status line —
  which is what makes a frozen clock legible as the feed-down claim rather than as a minute that
  happens not to have changed. The one `setInterval` left is the **feed** itself, whose entire body
  is *deliver a heartbeat*; it is what a socket does in the build. `README.md`'s *"live wall clock +
  day/night windows from the VIEWER's clock"* — the last sentence in the repo an implementer could
  read as licence for the interval — is corrected and points at A17 rather than restating it.
  **Untouched:** `docs/design/FLOOR.md` (card#7913 is editing it concurrently), D1, D2, the ratified
  look everywhere it is not the defect, and the staged `coord.*` communication layer.
- **card#7341** — **the floor's wall clock and day/night sky advance on `feed.heartbeat`, so they
  stop when the feed does.** The operator ruled on 2026-08-27 between three options
  (`docs/design/FLOOR.md § 13` decision 21 records all three): ship them **static**, carve an
  exception into § 6.3 for viewer-clock decoration, or drive them from a **delivered message**. The
  third is not a compromise — it is the only one that makes the element *earn* its motion. The
  ratified reference re-renders clock and sky on a **10-second interval from the viewer's clock**,
  which is § 6.3's second forbidden form and, worse than a rule violation, is a **second mover that
  keeps moving after the feed dies**: the page then never goes still, and AT-D3-6 (*the feed dying is
  visible within 45 s*) loses the observable it asserts. Driven by the heartbeat, **the clock stops
  with the page — and a stopped clock is the feed-down claim in the form every human reads
  instinctively.** ⭐ **§ 6.2 gains A17** (`edge`, driver `feed.heartbeat`, absence = *no message has
  arrived, which at 45 s is the feed-down condition itself*), and the note under that table no longer
  says A14 is *the only thing that moves unconditionally* — a sentence A17 makes false — but the
  **property** it was protecting, which is stronger: *everything on this page that moves without a
  delivered field holding it is driven by the heartbeat, so when the feed dies all of it stops
  together.* **Five constraints ride with the row**, each a defect if dropped: **no second hand** —
  the heartbeat is 15 s, so the minute hand **steps once a minute and is merely sampled four times**,
  and a hand jumping in 15 s steps is the *looks broken* that gets repaired with a timer; the clock
  reads the **viewer's** clock with only its **sampling** event-driven, reconciled at § 5.5 as the
  client's own narration and never a fact about a seat; it is **no authority on the time and grows no
  *as of* stamp** — the status strip already says how current the page is; a render **sets** it
  rather than animating it, and ⭐ **only a render that establishes or re-establishes a LIVE feed sets
  it** — a render the client makes *because* the feed is down never does, which is what keeps F1's
  **10 s poll** from handing the clock back the interval this ruling removed, under the name
  *setting*; and the hands' minute is **readable as text exactly once** (the element's accessible
  name), because an analog clock has no string for a test to assert on and an implementer would
  otherwise invent the target. **AT-D3-6 now ASSERTS the freeze**, in both directions and in a form a
  conformant client can satisfy: its heartbeat phase **crosses a minute boundary**, and the rendered
  minute advances **exactly once — on the heartbeat that crossed it, not on the other three and not
  between them** — then is identical at every read after the feed stops, across the boundary the
  silence phase spans. A **third RED is the exact repair a maintainer will attempt**: put A17 back on
  a 10 s interval and watch the freeze fail at that boundary. § 6.3's timer bullet gains the
  **driven-by versus read-at** distinction that decides the case (*motion that stops is caused;
  motion that continues was on a timer*), § 4.2's enumeration of what the floor screen contains now
  names the room, § 10.4's ⛔ *not admitted* bullet is replaced by the ruling, and § 6.4 **names the
  five animation rows whose reduced-motion form no test asserts** (§ 14 item 15) rather than claiming
  a coverage the two tests do not provide. **Out of scope and untouched:** the animation itself
  (this is the spec), `docs/design/floor-preview/floor-preview.html`'s own `setInterval` (card#7912
  owns the artifact), D1 and D2, and every other § 6.2 row.
- **card#7898** — **D3 admits the ratified art direction, and the asset gates move from absence
  to declared provenance.** The operator ratified a high-resolution, whimsical, modern
  (Ghibli-adjacent in *feel*) direction on 2026-08-26/27; `docs/design/FLOOR.md` as written
  **forbade it in three places** and its CI gate would have red on the first asset it needs.
  **§ 10.1 — Gate 2 stopped asserting an absence.** *"No image file in the character tree"* was
  the mechanised form of *the sprites are generated*, and it became false about the product
  rather than merely strict. It is replaced by the claim Gate 1 needs in order to mean anything:
  **every asset is a file Gate 1 can see**. The manifest gains a seventh column, **`origin`**, a
  closed set of `first-party` / `licensed`, each checked against the row's own source URL; the
  character-tree allowlist widens to `.ts` `.js` `.md` `.svg` `.png`, every member with its
  reason written down and `.avif` / `.webp` / `.jpg` / `.psd` / `.dat` still failing by name; and
  clause 2's purpose is restated as the load-bearing one — **an asset embedded inside another
  file has no path, so no row, so no provenance**. **What it COSTS is named in the document
  rather than discovered later**: the old gate was self-verifying, the new one rests on a row
  being *true*, and somebody who vendors commercial art as a `.png` under a `first-party` row
  passes everything. `bin/asset-provenance.py`, `docs/ATTRIBUTION.md`,
  `resources/characters/LINEAGE.md` and the workflow comment all say so.
  **§ 6.3 — the ambient-life bullet was SHARPENED, not deleted.** The ratified micro-animation
  (blink + wiggle while busy, slumped-asleep idle with drifting z's) was forbidden by a bullet
  that named *blinking*. The rule is now the **property** that bullet's own reason states —
  *motion neither held by a delivered field nor caused by a delivered message* — and every named
  motion survives as an example of it. A blink in every state is still forbidden; a blink held by
  a § 6.2 row's condition is the drawn form of that row. The only door in is still a § 6.2 row.
  **A6 (`idle`) gains a held loop** and its reduced-motion form is the static slumped pose; ⭐
  **a sleeper is never a gone seat** — `stale` / `offline` render the empty chair, stated as a
  § 7.5 rule and asserted by AT-D3-5 (with motion) and AT-D3-13 (without).
  **§ 4.5 — the capability floor is a property, not a technology**: a renderer crisp *at any
  camera zoom without resampling artefacts*, where it read *a 2-D tile renderer drawing sprite
  frames*. The camera is **navigation and animates nothing** — no § 6.2 row. The 1,280 × 800
  floor **stays, and the reason is restated** because *"it's vector now, it scales"* is exactly
  the argument the next reader will make: the rule is about **legibility**, never pixel density.
  **§ 10.4 is new** — the art direction as a specification, because the ratified artifact is not
  one: the ten seeded dimensions (7 silhouettes × 16 hues × 5 sizes + pattern/ears/sprout/eyes/
  mouth/accessory/tilt, **8,064,000** tuples), the intern seeding, ⭐ **the salt is a design
  choice — on visible repetition, widen the space or re-pick the salt, NEVER special-case a
  seat** (a special-cased seat is a stored appearance wearing a disguise), and the collision
  acceptance stated as a figure to be **measured** rather than the birthday estimate this entry
  is careful not to pass off as one. **§ 10.5 is new** — the IP line, **stated as unenforceable
  by gate**: no character owned by another rights-holder ships, and *review*, not a script, is
  what enforces it. The seeded **vibe line** is reconciled with § 5.4 honestly rather than by
  exception — appearance-class text is a rendering of *identity*, labelled as seeded, and drives
  no pose, label, badge or animation.
  **AT-D3-12's RED set was rebuilt** — its *vendored character* case tested a rule that no longer
  exists and would have passed vacuously. **Every new guard was seen to fail**: nine deliberate
  mutations of the gate, each watched red and restored. ⭐ **The control that matters most is the
  SVG false-positive pair** — a complex first-party SVG carrying a 1,926 B minified integer path
  must PASS while an SVG with an inlined `data:image/…` blob must FAIL. The first draft of that
  control did **not** discriminate (its longest run was 20 B, which any alphabet passes); it was
  rebuilt around the shape that actually collides — minified integer path data, where `-` is the
  separator — and clause 2's alphabet was narrowed from base64URL's superset to base64's own,
  which is what makes the drawing pass. **A gate that reds on correct work gets switched off.**
  `docs/PLAN.md` records D-07's supersession as an **append**, not an edit — a register records
  what was decided when. **Out of scope and untouched:** the `coord.*` event family and the
  communication layer (card#7897 — events before animation), the floor build itself (card#7341),
  D1, D2, § 8.1's cap arithmetic and the licence allowlist.
- **card#7341** — the operator-ratified INITIAL DESIGN for the floor UI lands as a working reference artifact (`docs/design/floor-preview/`): building cross-section + elevator navigation, per-floor themes, seeded 7×16×5 characters with one intern sprite per open subagent (cap 8, then +N), held-loop micro-animation, viewer-clock sky, and the staged coord.* communication layer. The build implements this; customization comes later by operator direction. *(This bullet was landed in the file's PREAMBLE — between two halves of a sentence, above `## [Unreleased]` — and is moved here by card#7898. `docs/VERSIONING.md`'s release step collects what is under the section heading, so a bullet above it is a changelog entry no release would ever pick up.)*

- **card#7837** — The fold sampled its version-bearing fingerprint AFTER the projector wrote, so
  every projector-written member was invisible to the delta feed. `StateRecompute::after()` read
  `SeatFacts::versionBearing()` on its own first line, which in `Fold::window()` is *after*
  `Projector::apply()` — making `$before` and `$after` identical on `context.*`, `model_label`,
  `enabled`, `selftest_failed`, D1's reporter badges and the `calls` rows behind `subagents`.
  **Measured on the suite's rig, before → after:** a `context.sample` emitted **no delta at all**
  → `changed: ["context","model_label"]`; an `enabled` flip emitted
  `["link_state","render_state"]` → `["enabled","link_state","render_state"]`. (Card #7827's entry
  above recorded the `context.sample` case as `changed: ["badges"]`; on a fixture where no badge
  moves it emits nothing, which is the same defect one step worse.) **The fix is ONE fingerprint
  sampled earlier and explicitly NOT a projector-returned diff**, which would be a second
  implementation of § 6.5's version-bearing set free to disagree with the first. `$before` is now
  a REQUIRED argument on both `after()` and `forSeat()` — required rather than defaulted so no
  call site, and no test seam overriding them, can keep sampling on the wrong side; the rule is
  the same one line everywhere: *sampled before the first write of the unit of work*. **Four fold
  call sites**: `Fold::window()`, `Fold::recoverOneAtATime()` (sampled inside the retry's own
  transaction, because attempt 1 may have written and rolled back), `Fold::quarantine()` (where
  nothing writes first, so the value is unchanged — stated rather than left to be inferred), and
  `mezzanine:rebuild`, which the card did not name and which § 6.6 requires derive through the
  fold's code rather than a copy of it. **A sibling audit found the same shape in two more
  writers, both fixed**: `mezzanine:retire` set `seats.retired_at/by/reason` before settling, so
  the delta announcing a retirement carried `render_state: "retired"` and left the client's
  `retired` object null (`["render_state"]` → `["render_state","retired"]`);
  `Sweep::orphanCloses()` and `Sweep::quiesce()` close `calls` rows before settling, and
  `subagents` / `subagents_open` read `calls` directly, so a desk kept rendering an intern the
  server had closed. Both are driven separately — job 2 fires on a still-live seat at the call's
  own 60-minute ceiling, job 6 only once the seat is `offline`, and a fix reasoned about for one
  of them is a fix with one instance of evidence; each patch gained `subagents` and
  `subagents_open`. **One instance of the class is reported and NOT fixed**:
  `Sweep::leavingLive()` has no settle of its own and defers to JOB 1, so its `stalled_since`
  clear lands above JOB 1's sample and `api_error_type` never reaches the wire. Moving JOB 1's
  sample above it was measured to be worse — JOB 6 settles in between and writes `render_state`,
  so a wider `$before` mints a SECOND transition row for one physical event (`[offline_quiesce,
  staleness_sweep]` where § 4.6 allows one), and **all 68 of the sweeper's existing tests passed
  under that mutation**, so `SweepJobsTest`'s job-6 case gains an exact-row-list assertion that
  was seen to fail under it. The fix is a decision about which settle owns JOB 5's writes; the
  hazard is recorded at the call site. **AND A SECOND, DISTINCT DEFECT IN THE SAME METHOD, JUDGED
  SEPARATELY AND ALSO FIXED:** `taskTier3()` re-stamped `task_as_of` to `now()` on every recompute
  while a title existed, and `task` is version-bearing — measured at **20 deltas over 20
  heartbeat+sweep passes, every one `changed: ["task"]`, now 0**. `as_of` is what § 4.9's
  freshness bounds are measured against, i.e. when the tier's value was *obtained*; re-stamping an
  unchanged answer claimed it had been re-obtained by a pass that only re-read it. Not fixed by
  dropping `task` from the fingerprint: § 6.5 states that set as a closed subtraction of ten named
  members and adding an eleventh is a D2 change. Two suite cases that had to fence their fixtures
  to a seat with no open call to dodge this now say so and are joined by one that drives the
  open-call fixture directly. `FeedSurfaceTest::test_a_projector_written_member_reaches_the_delta`
  is complete and green (it called `markTestIncomplete()` on its first line); every new assertion
  was seen to fail against the old ordering first, and each carries a control plus a REST-snapshot
  check that the surface which was already correct stayed correct. 297 tests / 3,643 assertions,
  from 293 / 3,561 with one incomplete. ⚠ Run on SQLite: this seat has no MariaDB credential, and
  nothing changed here is engine-specific.

- **card#7827** — Fleet-state PART B: the REST read plane and the WebSocket delta feed —
  `docs/design/FLEET-STATE.md` §§ 8.2, 8.2.1, 8.2.3, 8.2.4, 8.3, 8.4, 8.5, 8.6 and 9. **The four
  REST endpoints** (`/api/fleet/snapshot`, `/seats/{i}/{s}`, `/seats/{i}/{s}/timeline`,
  `/health`), the § 8.2.1 seat object in full, § 8.2.4's fleet-health object stated once and
  carried by all three of its surfaces, and § 4.10's 14-day retired-seat READ FILTER as one
  predicate every read query shares. **Read-side auth** (§ 9): the `feed_tokens` store, a `mzr_`
  `fleet_read` credential with issue/revoke commands, revocation checked per request and never
  cached, `token_wrong_surface` for an `mzn_` ingest token, and § 9's 120/600 req-min limits —
  each seen to fire and seen not to — plus D1 § 12.3's failed-authentication limit on the REFUSAL
  path, which took no rate-limit slot at all before, spending `RateLimiter::hitFailedAuth()`'s
  existing per-source-address budget rather than a second one. A request presenting NO credential
  is excluded (it is unauthenticated, not a failed authentication, and this surface has browsers),
  and so is a token that resolves to a revoked or expired row — D1 § 12.3's own exclusion, which
  matters twice over on a shared budget. **The timeline pages on a KEYSET CURSOR** `(received_at,
  id)`, issued by the server as the response's `next_before` and decided by a `limit + 1`
  look-ahead: `received_at` alone is not unique — one batch stamps one value across up to 200
  events — so the strict `received_at < ?` cursor a client could only derive from the response
  skipped every event sharing the boundary timestamp. Measured before the fix at 120 events in one
  batch: page 1 served 50, page 2 served **0**, and 70 were unreachable — `200 {"events": []}`,
  the one shape `ReadRefusal::badCursor()` exists to refuse, produced from a well-formed cursor on
  ordinary traffic. ⚠ A bare timestamp is now refused `422 bad_cursor`, which TIGHTENS what the
  endpoint accepts: § 8.2 names the parameter and specifies no type for its value, and refusing the
  assembled cursor is what keeps the lossy form from being re-derived by the next client.
  **The feed** (§ 8.3): four of its five message types as
  broadcastable events sharing one envelope and one channel name, published from the ONE place
  `state_version` is bumped, and a 15 s `mezzanine:feed-heartbeat` daemon that is deliberately not
  the sweeper's. `App\Events\SeatRetired` — card #7712's declared publication point — now reaches
  the wire. **AT-D2-7, AT-D2-8, AT-D2-16, AT-D2-19 and AT-D2-20 ship with their REDs DRIVEN**, and
  **AT-D2-21 and AT-D2-23 are now COMPLETE**: their primary REDs were wire-surface assertions card
  #7712 shipped undriven, and both are driven here (the omitted `fold_lag_ms`, and the vanishing
  desk). 56 new tests, 285 total.
  **⛔ AT-D2-15 IS NOT DELIVERED AND IS NOT APPROXIMATED.** Per-connection backpressure is a
  property of the socket server's outbound queue, which no application publish can observe — and
  `laravel/reverb`, § 8.3's pinned transport, is NOT INSTALLABLE on this tree: every version
  through v1.11.1 needs `guzzlehttp/psr7 ^2.6` against this application's `3.1.0`, and
  `composer require -W --dry-run` resolves only by downgrading guzzle 8.1.0 → 7.15.5, promises
  3.0.2 → 2.5.3 and psr7 3.1.0 → 2.13.1. A backpressure test written against a mock would test
  the mock. **Six D2 findings are REPORTED, not patched — the design doc is untouched:**
  (1) § 8.3's 250 ms COALESCING and § 8.5's `delta.state_version == local + 1` cannot both hold,
  because a merged message is indistinguishable from a lost one — this card ships one delta per
  version and names the cost; (2) § 8.2.4 declares five members non-null while declaring
  `db: "down"` reachable on the same object, so they are ABSENT rather than invented on that path;
  (3) § 8.2.3's `detail` enumeration omits this plane's `seat_predicates`, which Appendix A's S11
  requires per seat per predicate — added as an additive member under § 8.1's own rule, and
  § 8.2.4 was NOT its home (`sweep_seat_error`, card #7832, needs none: it is already a
  `seat_counters` row); (4) AT-D2-19's "redirect to the MFA challenge" contradicts § 2.2, and
  § 2.2 wins; (5) AT-D2-16's "closed at its materialized `orphan_due_at`" reads two ways and card
  #7712 chose one — asserted here only on what both readings share; (6) § 8.4 step 5's watermark
  and § 8.5's discard are the same comparison, so one is unobservable without the other.
  **⚠ AND ONE CARD #7339 DEFECT, FOUND HERE AND NOT CROSSED INTO** — *both defects in this
  paragraph were CLOSED BY `card#7837` below; the description is kept as the finding record and is
  no longer a statement about the code:* `StateRecompute::after()`
  samples its version-bearing fingerprint AFTER `Projector::apply()` has written the event, so
  every projector-written member — `context.*`, `model_label`, `enabled`, `selftest_failed`, D1's
  reporter badges, a late `subagents[].title` — is invisible to both the bump decision and the
  delta patch. Measured: an `enabled` flip publishes `changed: ["link_state","render_state"]`; a
  `context.sample` publishes `changed: ["badges"]`. The SNAPSHOT carries all of them correctly, so
  the watchdog and § 8.4's join are unaffected and a reload heals a browser; recorded as an
  incomplete test naming the mechanism and the affected population rather than left green. A
  second, smaller one is reported the same way: `taskTier3()` re-stamps `task_as_of` on every
  recompute, so a seat with an open call emits a delta on every fold pass — the 16 % of pure noise
  § 8.3 refuses. **⛔ AND A `blob` SHIPPED WHERE § 6.4 DECLARES `VARBINARY(16)`, IN BOTH TOKEN
  TABLES:** `$table->binary()` with no length compiles to `blob` on MySQL — `MySqlGrammar::typeBinary()`
  emits `varbinary({$length})` only `if ($column->length)` — and the suite runs on SQLite, where the
  two are the same, so nothing could notice. Fixed in `feed_tokens` and in the identical line
  card #7338's `ingest_tokens` migration carries, and now GUARDED: `MySqlColumnTypeTest` compiles
  the real migrations through the real MySQL grammar with no MySQL server, which proves the SQL
  text and explicitly not the engine's enforcement of it. **Three more not-delivered items are named
  rather than left to be inferred** — `fleet.health` ON CONNECT (§ 8.3 requires it; only the change
  half exists, and the cost is the up-to-15 s latency § 8.3 itself names, NOT blindness, because the
  unconditional heartbeat carries the same object), `feed_resync_required` (no writer anywhere in
  `app/`, and downstream of AT-D2-15 rather than independent: § 8.5 increments it only at the
  socket server's backpressure close), and § 6.4's `revoked_reason`, a column this application
  creates that the document's DDL does not contain. ⚠ Written for both engines and tested on SQLite;
  every MySQL-specific behaviour
  left unexercised is enumerated in the PR body (card #7523, the store host), and there is no PHP
  test lane in CI (card #7344), so this suite is SELF-ATTESTED and the mutation evidence in the PR
  body is the load-bearing part.

- **card#7712** — The three processes `docs/design/FLEET-STATE.md § 2.1` names and neither half of
  card #7339 built. **`mezzanine:sweep`** — a supervised 15 s daemon applying § 2.1's seven
  time-derived jobs (staleness, orphan-timeout closes, attention ceilings, compaction ceilings, the
  leaving-live clears, offline quiescence, the § 5 predicate-constant alarms), recomputing
  `link_state` / `render_state` for every seat and bumping `state_version` under § 6.5's per-writer
  rule; it is what makes `stale` reachable at all, because a seat that has stopped sending has no
  unfolded events and is never claimed by the fold. **`mezzanine:purge`** — hourly, scheduled,
  bounded 5,000-row batches under a 60 s budget, with `purge_backlog_rows` when it falls behind and
  a hard REFUSAL of any retention below `D2-MUST` #3's 10-day dedup window (§ 2.2: "deleting on a
  broken assumption costs the dedup guarantee — the safe direction is to keep"). **`mezzanine:retire`**
  — the only writer of retirement, doing § 4.10's whole act in one transaction: the three columns,
  the recomputed `render_state`, the `cause: operator` transition row, the `state_version` bump and
  the `seat.retired` publish, each of which had no producer before. The publish is dispatched
  **inside** that transaction and carries `ShouldDispatchAfterCommit`, so it is ordered by the act
  that sets the columns and is delivered only if the act commits — a rollback reaches no client.
  Creates § 6.4's `seat_predicates` and records all seven § 5 predicates at their own evaluation
  sites. **One seat's failure costs one desk and no longer kills the daemon:** the per-seat pass is
  inside an error boundary that logs, counts `sweep_seat_error` and continues, and a pass reports
  how many seats it skipped — without it a single reachable raise (§ 2.3's unseeded cursor clock)
  exits the process and, under a supervisor, crash-loops the fleet's time-derived transitions.
  **AT-D2-21** (a frozen fold cannot look healthy — `fold_lag_ms` computed from a basis two
  processes write, the badge, the episode counter, the never-folded seat) and **AT-D2-23** (a
  retired seat is rendered, not disappeared) have their store-side REDs driven rather than
  described; the PRIMARY RED of each is a wire-surface assertion Part B owns and neither is claimed
  complete here. 59 tests; every one of the seven jobs and every new check was SEEN TO FAIL under a
  named mutation whose landing was proved by `git diff` — including one mutation that did NOT red,
  which corrected a false claim in this card's own comments (job order is not what makes § 6.4's
  four deleted ENUM members unreachable; the disjointness of the two jobs' write sets is).
  **Three D2 gaps are REPORTED, not patched — the design doc is untouched:** § 6.4 declares no home
  for § 8.2.4's `sweep_last_run_at` / `purge_last_run_at` (a `plane_state` table is added and
  flagged), no receipt column for § 4.6's compaction ceiling
  (`sessions.compaction_open_received_at`, flagged), and `seat_predicates` cannot express § 5's own
  rolling-window criteria — so those four criteria are **not evaluated at all**: the alarm returns a
  named `cannot_evaluate` outcome rather than guessing in either direction. An earlier revision
  approximated them and claimed the error ran in a safe under-firing direction; that claim was false
  in both directions (a wall-clock proxy fired on the first evaluation after a sweep outage, and a
  cumulative share latched permanently on a months-old incident), and the approximation is gone
  rather than tuned. ⚠ Written for both engines and tested on SQLite: the sweeper's per-seat
  work takes no row locks, so `FOR UPDATE SKIP LOCKED` is untouched by this card and remains
  UNEXERCISED (card #7523, the store host) — and one MySQL-only exposure is now NAMED rather than
  wrongly justified: `Predicates::record()` is a read-modify-write, two of the seven predicates are
  written by two different processes, and the `SKIP LOCKED` claim its docblock used to rest on
  excludes only other *fold* workers. On the pinned engine that is a lost update, and the two
  writers take `seat_predicates` and `seat_state` in opposite orders. Corrected in the docblock,
  carried onto #7523, not fixable here. **Deploy note:** `sessions.compaction_open_received_at` is
  added NULLABLE with no backfill while `compactionCeilings()` requires it NOT NULL — no live data
  exists yet, so pre-existing open compactions cannot be stranded; a backfill would be owed if that
  ever stopped being true. The WebSocket delta feed and the REST snapshot remain Part B's, and the
  feed-bound halves of both acceptance tests are named rather than approximated.

- **card#7339** — PART A of the fleet-state card: the store schema and **the fold** — everything
  that turns accepted events into seat state. Creates `docs/design/FLEET-STATE.md § 6.4`'s
  remaining projection tables (`sessions`, `calls`, `attention_requests`,
  `seat_state_transitions`); adds `App\Fold\*` — § 4.3's derivation, § 4.5's link cascade,
  § 4.2's collapse, § 6.5's per-seat claim / visibility lag / purged-window branch /
  poison-event rule, and a projection for every one of the fourteen kinds the ingest accepts —
  plus `mezzanine:fold` (§ 2.1) and `mezzanine:rebuild` (§ 6.6), which shares the fold's
  `project()` rather than a copy of it. Fourteen acceptance tests from § 11 (AT-D2-1, -2 both
  hook orders and Case β, -3, -4, -5, -6, -9, -10, -11, -17, -22), each seen RED under its own
  named mutation before green, with the mutation's landing proved by `git diff` rather than
  assumed. **Doc-sync: § 6.4's `sessions` gains `last_turn_background_tasks_open`** — the fourth
  component of § 4.3's `L`, which card #7337 added to the derivation and not to the DDL; rule 4,
  the only rule that can mint `idle`, reads it. ⚠ Written for both engines and tested on SQLite:
  `FOR UPDATE SKIP LOCKED`, `ascii_bin`, `ON DUPLICATE KEY`, `ALGORITHM=INSTANT` and
  `DATETIME(3)` are UNEXERCISED, and `SKIP LOCKED` is the fold's concurrency correctness
  (card #7523, the store host, is the operator dependency that closes this). The WebSocket delta
  feed and the REST snapshot are Part B; the sweeper, `mezzanine:purge` and `mezzanine:retire`
  are claimed by neither part.

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

- **card#7335** — **the producer half of D1**: `fleet-reporter/` — one zero-dependency Node file
  driven by Claude Code hooks and by the statusLine integration, D1 § 17's captured payloads
  vendored beside it, and a hermetic acceptance suite. This is D1 § 16's artifacts 0–4; the ingest,
  the server-side ledger and the staleness/predicate alarms are D2's and card #7338's. Built to the
  card's three properties, each driven RED before GREEN. **Never blocks the seat** — one try/catch
  per entry point with `process.exit(0)` in a `finally`, no stdout from a hook on any path, every
  write a synchronous append; the network modules are required **lazily**, which makes that property
  structural rather than observed (a hook process never loads an HTTP client at all) and takes 67 ms
  of module loading off every hook fire. **Survives the bridge being down** — hour-bucketed
  append-only spool, per-bucket read offsets, exponential backoff with full jitter, and a poison-pill
  rule that costs one bad batch its own events and never the stream behind it; every discard is
  counted, and a 30-hook outage followed by a restore delivers every event by id. **Programmatic end
  to end** — every field is read from a hook payload or from process state, no model is asked to
  describe itself, and the descriptor allowlist is the control with redaction as the second layer.
  ⛔ **Five defects the suite found, every one of them silent in production**: a new flusher could
  never claim ownership of `state.json`, so the cursor and `seq` never persisted and every restart
  re-sent the spool from `seq` 1 — **dedup absorbs duplicate events but not a duplicated ordering
  key**; a single `(bucket, offset)` cursor permanently skipped any bucket older than itself, losing
  the straggler writes § 11.1's deletion grace exists for, with nothing counted; corrupt spool lines
  were re-quarantined and re-counted on every retry pass, inflating the loss numbers the floor
  renders precisely during an outage; `selftest`'s own sanitizer fixtures incremented the seat's live
  `sanitizer_redactions`, a diagnostic moving an operational counter; and the statusLine passthrough
  threw `ERR_UNKNOWN_ENCODING` and swallowed it, blanking the status line of every seat that had one.
  ⭐ **Review then found that the known-VALUE leg of `redactSecrets` had no live subject at all.** The
  function has two independent legs — values the process holds, and shapes (`CRED_PREFIX_RE`) — and
  every credential in the suite carried a shape the regex already matches, so **deleting the value
  leg outright left the whole section green**, reproduced before it was fixed. The only secret ever
  registered was `config.token`, which by validation always carries the `mzn_` prefix, while
  `proxy_url` is `https://user:password@host:port` and § 3.1 constrains nothing inside it — an
  arbitrary password, matched by no prefix in the shape list, held in memory by every hook process.
  `registerConfigSecrets` now registers every configured secret, and the new test isolates the leg
  with a control in each direction. ⚠ **Two false-clean traps caught while building it are named
  rather than passed over**: the first sweep read the spool line the harness itself had planted, so
  RED and GREEN reported the same hit; and the corrupt line was initially placed behind a valid
  event, where the disposal rule commits it only on a successful POST, so against an unreachable
  proxy it never reached the sink and the sweep was reading a file that did not exist.
  ⛔ **A failed spool append lost the event UNCOUNTED** — against D1 § 0 item 9 (*a counter for every
  discarded event*) and § 11.4 (*nothing is discarded uncounted*), and reproduced rather than
  inferred: with the current bucket made a directory and `counters/` + `log/` left writable, a real
  `PreToolUse` gave rc 0, zero spooled events and a counter sink reading `"c":{}` — **the seat
  renders healthy while dropping events**. **Fixed in the PRIMITIVE rather than at the four
  call-sites, only one of which was correct**: `appendLine` counts its own failure keyed by subtree
  and retries once through an uncached open, which previously spent exactly one event per transient
  error. Two more of that round: the bucket name is now derived immediately before the write, which
  the primitive's own header had always claimed and only `journal()` implemented; and
  `projectLabel()` returned `basename(cwd)` *before* sanitizing, so § 7.3 rule 6 could never match a
  bare username token and `cwd=$HOME` put **the OS username on the wire** — literally conformant with
  § 6.1 and a § 1 non-goal violation.
  ⇒ **D1 was the wrong side once**: § 6.1's `harness_label` pattern could not accept the value that
  same row mandates (`claude-code/2.1.240` contains `/`), and left alone, an ingest implementing
  § 6.1 literally would 422 the first correctly-configured seat, reject all 200 events in the batch
  and quarantine them permanently. The pattern is widened, and the check now re-reads the pattern AND
  the example out of the doc row and asserts them against each other, so the two copies cannot drift
  again. ⚠ **The latency assertion moved behind `FLEET_REPORTER_PERF=1` on measurement, not
  preference**: subtracting a baseline was tried and MEASURED to fail — interleaving baseline and
  reporter samples so both see the same load gives an attributable median of **53 ms** idle against
  **82 ms** and **163 ms** under six busy cores, with the p99 difference swinging **−1,070 ms** to
  **+520 ms** on identical code, so the p99 is noise outright and the median — the better statistic —
  still triples under load. What is asserted on every run is what is not load-sensitive: every hook
  exits 0 and prints nothing on stdout on every adverse path. ⚠ **`K.FLUSH_MIN_EVENTS` was DELETED
  rather than wired** — § 11.5's early-flush leg needs the flusher's whole duty cycle restructured,
  the 10 s leg bounds the delay either way, and a defined-but-unread constant reads as an implemented
  trigger to anyone grepping for it. ⚠ **Named rather than assumed**: a real Windows install
  (card #7336), a real `/clear` against a real subagent (AT-1, card #7337), the `proxy_url` CONNECT
  path, and reachability against a real ingest host are all unverified here. Suite: 189 → 217 checks,
  exit 0 (219 under `FLEET_REPORTER_PERF=1`).

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

- **card#7456** — **D2, the fleet-state model and feed contract** (`docs/design/FLEET-STATE.md`):
  everything between a durably-accepted batch and the floor — the per-seat state model, the MySQL
  store on its dedicated host, the fold, retention, the server-side counters and the two read
  surfaces, with twenty-three acceptance tests each carrying its RED, plus the mechanical verifier
  `tools/design/verify-fleet-state.py`. D1 owns the wire and is cited, never restated: its five
  `D2-MUST` constraints and the **twenty-nine** further places D1 addresses this document are
  enumerated in Appendix A, one row each, pointing at the section that discharges them.
  ⭐ **State is a PURE FUNCTION of stored facts, not a stored state machine** — five facts, one
  precedence, recomputed each fold pass, because a machine has states that can be entered and not
  left (the one-way trapdoor D1 had to fix twice) and a function over bounded facts cannot; every
  open fact carries a stated ceiling and `offline` quiescence is the backstop under all of them.
  ⛔ **DELIVERY IS NEVER ACTIVITY**: every receipt-derived timestamp is named for receipt and may
  drive only transport states, activity claims come only from the seat's own turn and tool events,
  and `reporter.heartbeat` is explicitly not one. The fleet PM channel's maxim — that a stamp which
  refreshes only when a seat posts *"corroborates; it cannot exonerate"* — is quoted verbatim and
  attributed, and **AT-D2-4's RED is the single line that would break it**.
  ⇒ Also settled rather than deferred: the feed's ordering key is a server-minted `state_version` and
  **not** D1's `(seq_epoch, seq)`, because orphan closes, staleness, ceilings and quiescence mint
  transitions with no wire event behind them, so a `seq`-ordered feed could not sequence precisely
  the transitions that fire when a seat goes quiet; `blocked` outranks `working`, stated loudly
  because it is the one place two D1 rules are simultaneously true and D1 states no precedence;
  fail-posture is stated per path and never inherited; and **`events` is deliberately NOT
  partitioned** — MySQL requires every unique key to contain every partitioning column, so a RANGE
  partition would force the dedup key to include the date and `D2-MUST` #3's dedup would silently
  stop working. Retention is 14 days because the dedup guarantee IS the unique key on `events`, so
  the chain 8 d spool < 10 d dedup < 14 d retention is one inequality that moves together. **Four D1
  amendment needs are RECORDED in § 14 rather than edited into D1** — closed later on card#7521.
  ⛔ **Six of round 1's 35 findings needed design changes rather than wording**, chief among them
  that **the frozen-fold detector was written only by the fold, so it died with the thing it
  detects**: `fold_lag_ms` is now computed at read time from a basis the ingest and the fold write
  separately, and its operand is the OLDEST unfolded event rather than the newest, which reads ~0 on
  a busy seat. § 4.5's five self-referential link-state predicates became an ordered cascade with a
  total function and a defined value for a silent seat, and leaving `live` now CLEARS `stalled` and
  `blocked` at the stale boundary while only MASKING `idle`, with the asymmetry and its reason
  stated.
  ⚠ **The review loop's stop-observable fired on the INSTRUMENT, so the instrument was repaired
  first** and the document fixes were validated under the repaired tool. G5 was a bare substring
  search over the whole cited section — vacuous for 24 of 50 § 12 rows, and **a live 2 s → 7 s drift
  passed green**; G6 built its cited set from the whole row, so D2's own § links satisfied a
  D1-citation requirement; G3 hard-coded 8940/86400, 8192 and the seat counts 4/50/200. Each is now
  re-derived from its definition site and PERTURBED per row to prove the match can fail, with the
  residue printed honestly — **64 of 81 proven discriminating, 17 not** — instead of counted as
  passes. **G8 checked writers against rows and not rows against writers**, so a § 7.2 counter no
  rule increments was invisible (measured: re-adding `offline_quiesced_attention` passed green); the
  mirror direction's first run found five pre-existing rows with no writer. **The mirror then had to
  be tightened from a bare name match to WRITERSHIP** — a declaration list satisfied the old
  predicate, and so, per the second gate, did a sentence DENYING the counter exists — after which its
  first run named four more, one of them the live sixth instance the previous pass could not see.
  ⛔ **A sibling audit of one shape — a D2 claim about D1 content asserted without reading D1 — found
  the delta-volume population missing `compaction.start` / `.end`**, which § 3.2 declares ARE
  activity events: 8,940 → **8,980/seat-day**, with an independent cross-check the document now
  carries (every D1 kind but the heartbeat, so D1's own ceiling sum 10,420 − 1,440 = 8,980). All
  eight occurrences and every dependent figure were re-derived rather than restated, and G3 re-adds
  the sum from the document's own components and is proven capable of the other answer.
  ⇒ **AT-D2-14's hostile export is a GREEN, and the row asserting an abort was a check that could
  never fire.** The row claimed the test-store guard aborts under `DB_DATABASE=mezzanine php artisan
  test`. It cannot, while the pin is intact: PHPUnit's `<env>` writes only `putenv`/`$_ENV`, a shell
  export lands in `$_SERVER`, and Laravel reads `$_SERVER` first — so the `<server>` twin beats the
  export, **the resolved value never moves, and the guard has nothing to refuse**. Measured during
  card #7334's build: the suite PASSES under that export. **A check that can never fire is the mirror
  of the check that cannot fail**, so the row now asserts the green and reads the refusal from the
  third RED, which deletes half a pin — the only lever that moves the resolved value. The sibling
  audit found the same false claim in four more places, all corrected: § 6.2's proof bullet, the
  AT-run-order row, the closing summary, and `docs/PLAN.md § 3`'s acceptance line for the
  MySQL-provisioning card (card #7523), **where it would have sent a future builder chasing a refusal
  that cannot happen and then weakening a correct pin to produce it**.

- **card#7457** — **D3, the floor UI specification** (`docs/design/FLOOR.md`): everything after a D2
  JSON object reaches a browser — the lobby, the floor and the desk drill-down, the identity mapping,
  the closed animation table, degraded rendering, every failure path with its observable, asset
  provenance as a build gate, and seventeen acceptance tests each with its RED. D2 owns the state
  model and is cited, never restated; the **thirty-eight** places D2 addresses this document and the
  **twelve** more D1 does are enumerated in Appendix A, one row each. Ships
  `tools/design/verify-floor.py` — eight guard classes, every population re-derived from D2 rather
  than stored in the checker, each watched failing against a planted defect before its pass was
  claimed.
  ⭐ **THE CLIENT DERIVES NO STATE, and the seven things it computes for itself are a CLOSED list**
  (§ 2.1): every rendered fact names the D2 field it comes from, and the verifier reds when a source
  cell names a field D2 does not send. A closed list is checkable against a candidate computation;
  *"only presentational"* is not.
  ⭐ **The honesty principle is a TABLE, not a principle** — sixteen animations, each with the wire
  field or message that drives it and the exact edge that starts it, and **an animation with no row
  is a defect**. The renderer must log `(animation_id, seat, cause)` for every animation it starts,
  which is what makes **AT-D3-1** able to fail: its RED is adding idle breathing to the character
  sprite, the single most natural thing to add to a pixel-art office and the thing that would quietly
  make the floor's motion meaningless. ⛔ **No ambient life at all** — motion is the floor's
  vocabulary and decoration would spend it on nothing; a state-held loop may say WHICH state, never
  HOW MUCH, at one frame per D2's 250 ms coalescing tick, so no loop can appear more informative than
  the feed that drives it. ⚠ **§ 6.1 landed carrying the attribution card#8161 later withdrew**
  (*"— operator, via the proposal"*, at two sites), and § 6.3's blanket refusal of decorative motion
  is scoped there too; this bullet describes the document as it shipped.
  ⭐ **Identity is a pure function, not a stored position** — the desk slot is FNV-1a-32 of D1's
  config-resident `(install_id, seat_id)` with deterministic probing, so two browsers, two reloads
  and two server restarts agree with no server field and no local storage; sorted order and arrival
  order are both refused with their failure modes (a sorted floor shifts wholesale when a seat is
  provisioned; arrival order is not a function of the rendered set, so two browsers disagree). The
  cost is paid openly: on a collision an arriving seat can displace an incumbent, bounded to the
  chain, **animated as a move** because nothing on this floor moves without a cause, with the
  frequency stated as `N/S` — 4 seats over 12 slots, **1 in 3** on the shipped map.
  ⇒ **The subagent cap STAYS at 8**, closing D2 § 14 item 9, and the arithmetic is re-derived by the
  gate rather than transcribed: **2,080 B** spare (8,192 − 6,112), ⌊2,080 ÷ 263⌋ = **7** more would
  fit, the cap **could** reach **15** at 7,953 B, and 16 breaches at 8,216 B — **24 B over**. It
  stays at 8 on a D2 fact rather than a taste judgement: **the drill-down does not read
  `subagents[]`** — it reads the seat-detail response, whose open-call list D2 § 8.2.3 serves *"in
  full (not capped at 8)"* — so the array's only consumer is the floor's side table, and the spare is
  the margin the next field addition will need.
  ⛔ **Asset provenance is two build gates, one of them an ABSENCE**: every asset file owes an
  `ATTRIBUTION.md` row carrying source URL, author, SPDX identifier, retrieval date and the vendored
  file's SHA-256, a missing row or a mismatched hash failing the build; and because character art is
  GENERATED from the seat key by the ported MIT generator, the second gate asserts **there is no
  image file in the character tree at all** — a hash denylist could only refuse the copies someone
  thought to enumerate, an empty tree refuses the one nobody anticipated. The licence allowlist is
  closed at `CC0-1.0` and `MIT`, and widening it is an operator decision, not an implementer's.
  ⚠ **Nothing is invented where the contract cannot answer**: five gaps are filed as D2 amendment
  needs, each with what is rendered in the meantime — the timeline and detail endpoints have no field
  tables, the feed has no message for a seat or install entering the population, D2 refuses machine
  tokens on the socket for a revocation property the browser's own session shares and D2 does not
  address, no compaction fact reaches a consumer, and `fleet.reload.reason` has no member set. The
  task-title fallback is **tier 3 only**, carried forward as an open question, because the proposal's
  three tiers are not in this repository and inventing them from the phrase would put a guessed rule
  in a contract.
  ⚠ **The closing rounds' recurring finding was RESTATEMENT — a fact spelled at N sites drifts — so
  six facts were each given one owner and a guard**: the dark-only desk render (12 sites), where the
  derivation lag renders (5 sites), the animation log's episode schema, record-versus-lobby, § 2.4's
  marker rule, and the AT build-order rule (6 instances); G9's population is derived rather than
  stored; and **G6's Appendix-A recognizer stopped being the literal `D3`** — it is now `D3` plus the
  render-directed phrasings D2 and D1 actually use, matched **wrap-tolerantly**, because grepping for
  `D3` alone reported clean while D2 § 4.7 and § 4.8 placed three render obligations this document
  neither listed nor discharged, and a line-scoped list would have left the check clean over a phrase
  typeset across a wrap exactly as before.

- **card#7521** — **D1 and D2 are reconciled: the eight § 14 items D2 filed against D1 are closed at
  the positions § 14 itself named**, plus four Gate-C findings on the D2 side and the doc-sync those
  amendments oblige. **Item 1** — the flusher's 90-minute `inferred_silence` close emits **no**
  `turn.end`; the server closes the turn, because a hook-path exception buys nothing the idempotent
  server close does not already provide and puts a second writer on a fact one writer owns.
  **Item 2** — both orphan ceilings are measured from the server's `received_at`:
  `started_at = event_time` is right for a *duration* and wrong for a *ceiling*, because seat clock
  skew must not move a server ceiling. **Item 4** — the ordering key becomes
  `(event_time, seq_epoch, seq)`, total across an epoch reset and reducing to the old key whenever
  the epoch is constant, with § 10.2's out-of-order row moved so the key has one spelling.
  **Item 5** — sticky-until-restart is confirmed INTENDED, with its reason (a badge that clears
  itself is indistinguishable from one that never fired) and the consumer clause, and no wire change.
  **Item 10** — a clean turn's `idle` survives a later `session.end`, the idle rule reading two facts
  off the `turn.end` that a session end falsifies neither of. **Item 11** — `mzr_` joins rule 3's
  known-prefix regex.
  ⭐ **Item 12 took the CLASS fix, not the row fix.** D2 § 14 named two possible closes — re-word
  § 12.7's consequence column, or state that the server's badges are a separate vocabulary — and the
  second is the upstream one, so § 12.7's preamble now says as a class that no badge that table names
  is a member of § 9.3's array. That disposes of the `seq_collision` and `batches_refused.<error>`
  rows the same item flagged in the same breath; **a row-by-row fix would have left both standing and
  the next server counter would have minted the contradiction again**.
  ⛔ **Item 13 is the marker convention, and its root cause was D1 § 1's own false claim**: § 1 said
  its only D2 marker was `D2-MUST` with *"exactly five such constraints"*, against Appendix A's
  twenty-nine further obligations. § 1 now declares `D2-MUST` for the five numbered constraints and a
  `D2:` prefix (or a *constraining D2* note) for every other consumer-addressed obligation, **on the
  obligation sentence rather than somewhere in its section** — which is the property that let S29 be
  walked past three times while a section-level check stayed green.
  ⇒ **The verifier now follows the convention rather than the mention**: G6 builds a second
  population from markers ON the obligation sentence, splits Appendix A against it, and **fails on a
  D1 section that mentions D2 without marking which sentence is the obligation** — the S29 shape
  itself. Measured on the plant rather than argued: with D1 § 12.5's marker stripped and the
  section's other D2 mention left intact, **the `origin/dev` tool still derived 28 / 1 and carries no
  such check**. **Manual residue: 14 rows before, 1 after**, and the one left is printed by name on
  every run even at zero — S25's D1 source is a decision-register row rather than a section number,
  so no marker convention in D1 can reach it, and minting a normative D1 sentence to make it
  greppable would have contradicted a recorded decision.
  ⚠ **Gate-C on the D2 side, where the sharpest of the four is a stored denominator**: two prose
  comments said the delta-volume sum has *"six"* components against a seven-operand sum the tool
  itself prints as seven, so the count is struck from the prose and **the neighbouring `< 6` floor is
  RE-DERIVED rather than kept** — it is now the operand count of the sum's own arithmetic, read
  independently of the value extraction it guards, because a pinned floor goes on passing after the
  document grows a component, which is the one change the control exists to catch. Also: § 8.2's
  `snapshot_denied` pointer sent the reader to § 9 when § 8.6 owns the rule; § 4.6's quiescence write
  sequence now writes `last_turn_aborted_count: 0`, so the precedence paragraph restates a written
  rule instead of asserting a value nothing sets; and § 6.5's version/platform volume disposition is
  re-derived from § 8.3 and § 12 rather than guessed. § 14 items 3, 6, 7 and 9 stay open — **none of
  them is D1's**.

- **card#7455** — **D1, the keystone P0 design artifact** (`docs/design/EVENT-SCHEMA.md`): the
  complete contract between `fleet-reporter` — a zero-dependency Node hook bundle on every agent
  seat, Linux and Windows — and the Mezzanine ingest. Fourteen event kinds with field-level tables
  and worked payloads, the batch envelope, the client-side sanitizer, the spool and flusher
  mechanics, the server's response contract, and twenty-two acceptance tests each with its RED,
  written to `docs/PLAN.md`'s D-14 standalone-implementer bar. `docs/VERSIONING.md § Wire
  compatibility` keeps the versioning policy and gains **rule 7** (a new event kind and a new
  closed-enum member are backward-compatible — ignore-or-coerce-and-count on the receiver); § 5
  records only how these fields comply, and cites rather than restates.
  ⭐ **Kill-vs-complete is an explicit open/close call ledger, not an inference from turn
  boundaries.** A `/clear` SIGKILLs an in-flight subagent tool call and no `PostToolUse` ever fires
  (measured 26/26, roundtable #341/#340), so **absence must never read as completion**: every
  `PreToolUse` opens a call with a reporter-minted `call_id`, and every session or turn boundary
  REAPS the calls still open and closes them `aborted` with a stated `abort_reason` and
  `close_source` ahead of the boundary event, which then names them in `aborted_call_ids`. One
  consequence makes the whole thing checkable at the wire: **`idle` may be minted ONLY from a
  `turn.end` with `end_reason` `stop_hook` and an empty `aborted_call_ids`**. The cost accepted is
  over-eager aborts for a call legitimately outstanding at Stop, bounded by the late-completion rule
  — a completion is an observation and an abort is an inference, so the observation always overrides,
  and a rising `late_completion` count is the signal that a reap rule is wrong.
  ⭐ **Sanitization is an allowlist first and regexes second**: a descriptor is built only from an
  explicitly allowlisted input key of an explicitly allowlisted tool, and every other tool —
  including every `mcp__*` tool, whose input schemas third parties define — contributes `tool_name`
  and no descriptor at all. The redaction pass exists because allowlisted text can still carry a
  credential, **not as the primary control**, and that ordering is what the RED fixtures test:
  fixture 8 removes the allowlist alone and must fail alone.
  ⛔ **Silence is the failure mode this design fears most, so liveness is asserted continuously
  rather than inferred.** The prior art is a fleet predicate keyed on the undocumented
  `CLAUDE_CODE_CHILD_SESSION` marker that went constant on a harness upgrade and left two consumers
  dark for **30 days with no log line**. So identity comes from an install-time config file and never
  from the environment; no emission is gated on any harness marker (classifiers label, they never
  suppress); every predicate reports both branch counts and the server alarms when one goes constant;
  `/clear` is detected by two independent signals whose counters diverging is itself the self-test;
  and a 60 s heartbeat with a 300 s staleness alarm makes *reporter dark* a rendered state rather
  than a quiet desk.
  ⛔ **Round 1 found the schema resting on three false premises about the harness, and each took a
  redesign rather than a patch**: `session.end` becomes an OBSERVATION (`SessionEnd` exists, so the
  four-way inference is gone and one inferred member remains, reversible via `session_reopened`, with
  the supersede-on-different-session rule deleted outright because it made two terminals on one seat
  abort each other's healthy calls); subagent matching is rebuilt on `agent_id`, so parallel
  dispatches stop losing their stop edge; and **`blocked` becomes a PAIR** — it had an entry event
  and no exit event.
  ⭐ **Round 3's class fix is the one worth carrying: hand-transcribed facts about another product's
  schema, with nothing binding them to a source and nothing able to red when they diverged.** Round 1
  found two wrong; **the round-2 fix corrected those instances and minted five more**, so with
  per-instance patching having failed twice, this round landed the binding first. Fifty-six real hook
  payloads across ten events were captured from Claude Code 2.1.240, § 6.0's fact layer was rebuilt
  on them — 36 rows, each **MEASURED** (fixture + version pin), **DOCS-CITED** (source + date) or
  **UNVERIFIED** (cost + closure act) — sixteen of those payloads are reproduced in § 17, one per
  distinct shape, sanitized only by replacing ids and paths with placeholders of the same shape and
  length, beside five labelled stubs for the hooks `claude -p` cannot drive; and a SELFTEST-MUST
  makes the reporter assert its expected payload keys against those fixtures and red on divergence.
  ⭐ **What the capture found that no review did**: the dispatch tool's payload `tool_name` is
  **`Agent`, not `Task`** — keyed on `Task` alone, `subagent.spawn` would never have fired, no
  `agent_id` would ever have bound, and the descriptor allowlist would have returned null for every
  dispatch; `Stop` does **not** fire inside a subagent (settling the highest-cost open fact);
  `PostToolUseFailure` carries `is_interrupt`, the harness's own kill-vs-fail discriminator; and a
  permission-refused call fires no close hook at all. It also **overruled the review** on three key
  names — `SessionStart.source`, `SessionEnd.reason` and `PreCompact.trigger` are real, while the
  asserted `session_start_reason` / `session_end_reason` / `compact_reason` occur nowhere in the
  installed binary.
  ⛔ **Round 4 bound the VALUE sets as well as the key names, and both of its blockers lived in that
  gap.** The call index's `open` record carried one `agent_id` field meaning two opposite things —
  the scope a call was opened in, and the child a dispatch spawned — and splitting it into
  `agent_scope_id` / `child_agent_id` exposed that **no rule in the design ever closed a call opened
  inside a subagent**, because `Stop` does not fire there. And `reporter.heartbeat.degraded` was
  `array<enum>` with its member set stated nowhere and its two examples spelling one member two ways;
  twelve members are now declared in § 9.3, named after their counters, and **the array's bound is
  that table's size rather than a chosen 16**. `notification_type` was corrected **14 → 16** against
  the binary — the omitted pair being `elicitation_dialog` and `elicitation_url_dialog`, the two the
  `elicitation` branch depends on, which no guard could see because the guard bound key names and not
  value sets — and `notification_kind.other` was **deleted as structurally unreachable rather than
  hedged**.
  ⇒ **`tools/design/verify-event-schema.py` and `verify-harness-facts.py` ship with the document**,
  re-deriving their ground truth every run rather than storing it; the harness verifier reads key
  sets and enum value sets out of the installed binary, and **its controls ABORT rather than report
  clean** — two declarations must resolve to different sets, no set may be empty, and a fabricated
  field must raise. Every check in both was seen to fail on a planted or real defect before its pass
  was trusted.
  ⚠ **One figure was wrong three rounds running, in the same direction each time, so it now has one
  home and a gate**: the heartbeat's fill time read *"50+ days"* from a 500 B assumption, then
  *"~25 days"* from a *"~900 B"* measurement of the worked example **taken with its 524 B `counters`
  object left out**. It serializes to **1,487 B**, so a heartbeat-only seat fills the 32 MiB spool in
  **~15.7 days** — the conclusion survives with a narrower margin — and `verify-event-schema.py` now
  re-serializes that example on every run and reds if any figure in the paragraph disagrees. The
  superseded value was deleted at its three other sites rather than re-synced, so the fill time has
  one home and the others state the property they actually rest on.
  ⚠ **The raw 56-payload capture run stays UNCOMMITTED by PM ruling** (2026-08-23): this repository
  is public and the captures carry a real seat's session ids, cwd paths and prompt text, so § 17's
  reproduced fixtures plus the binary-derived guard are the durable evidence and the 56-run counts
  stay labelled run-provenance, **unfalsifiable by design**.

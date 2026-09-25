#!/usr/bin/env python3
"""Prove the three repo-only design verifiers CAN red, against the bytes about to judge a PR.

WHY THIS FILE EXISTS.  Until card#7929 the four `tools/design/verify-*.py` gates ran in zero
workflows and zero hooks: D1/D2/D3 cited them as "reds the gate" in the present tense while
nothing pointed them at anything.  `.github/workflows/design-doc-verifiers.yml` closes that.  But
a workflow that has never been seen to fail is the same defect wearing a YAML file -- an arm that
only ever ran green is an arm nobody has seen work -- so this harness plants a defect of each
verifier's own headline class and requires the verifier to reject it.

WHAT IT ASSERTS, PER VERIFIER.  A DIFFERENTIAL, not an absolute:

    the mutant's output carries a failure naming the planted figure,
    and the control's output does NOT.

That is deliberately weaker than "control passes, mutant fails", and the weaker form is the
correct one: an absolute control conflates the GATE's health with the DOCUMENT's health, so on a
PR that legitimately reds a verifier this harness would red too, burying the real message under a
control failure that says nothing the author can act on.  The differential answers the only
question this file is asking -- does the verdict RESPOND to this defect -- and keeps answering it
while the document is broken.

WHAT IT DELIBERATELY DOES NOT ASSERT.  Not coverage: the plants below prove the guards they target
live -- never that the rest of the guard classes those verifiers carry do.  And not the GUARD: the
differential attributes a red to the plant, never to the leg the plant's comment names -- a plant
that a broader leg of the same verifier refuses anyway reds whether or not the narrower leg works,
so it is the author's job to plant where only the named leg can catch it, and the harness cannot
tell when that was not done (PR #232 round 3, F1: `nonascii-digit` on a `[1-9]` digit red with and
without the `re.ASCII` it stood for).  How many plants, over
how many verifiers, in which kinds, is counted at run time and printed on the last line rather than
written here, so the sentence cannot drift from the list.  Two plants can share one guard class
(both G3 equalities do) without either being redundant: they discriminate opposite
directions of the same check.  Nothing here is
evidence about `verify-harness-facts.py` (see the workflow header for why it is unwired) or about
`floor-preview.selftest.mjs` and `floor-preview.browser.mjs`, which carry their own planted
controls internally and need no harness around them.

WHY A COPY OF THE TREE, AND WHY `git ls-files`.  Each verifier derives its root from its own
`__file__`, so judging a mutated document means giving it a mutated TREE -- never editing the
checkout, which on a developer's machine is live work.  The population is the tracked files as
they exist in the WORKING TREE, so the harness judges the bytes CI actually checked out rather
than a revision; the tree is small enough (the file count is printed on the first line of every run,
never written down here) that a per-plant copy is cheaper than being clever.  The whole
tree is copied rather than the four documents, because `verify-event-schema.py` resolves path
references out of `docs/VERSIONING.md` and reds on files a partial tree would be missing.

EVERY PLANT IS RE-READ, NEVER STORED.  A plant's anchor brackets the thing it perturbs and the
perturbation is computed from what the document SAYS there -- so a legitimate edit to that figure or
that name moves the plant with it instead of turning this file red.  The kind is named per plant,
because a gate can only be proven on a defect of its own class:

  `bump`    -- +1 to a figure the verifier RE-DERIVES (a serialized size, a re-added sum, a cap
               subtraction), which is the class "a stated figure drifted from what re-derives it".
  `rename`  -- suffix a NAME the verifier holds against a declared table, which is the class "a
               token was renamed on a surface outside the gate's population".  card#9303 is why
               this kind exists: `verify-fleet-state.py`'s G7 collected its message names with a
               backtick-delimited regex, so § 8.3's and § 8.4's pseudocode and JSON fences -- the
               surface an implementer BUILDS from -- sat outside it, and a rename planted there ran
               green through every verifier.  The plant below is placed inside a fence deliberately:
               a `rename` plant in backticked prose would pass against the narrow population too and
               would prove nothing about the surface the card is about.
  `narrow-up`, `narrow-sql`
            -- leave the anchored migration UNTOUCHED and add a later migration beside it that
               narrows the column the anchor sizes by one, which is the class "the store's
               effective width moved in a file the check did not read correctly".  `narrow-up`
               narrows in `up()` through the schema builder and restores the width in `down()`;
               `narrow-sql` narrows through a raw `ALTER TABLE ... MODIFY`.  card#9296 round 4's
               review is why these exist: check 12 read the last width in the last file, `down()`
               included, and passed both.
  `bold-bump`
            -- `bump`, with the bumped figure also wrapped in `**`, which is the class "a figure's
               EMPHASIS moved it out of the check's reach".  card#9326's review is why this exists: a
               row match that expected an unbolded figure passed a bolded drifted one at rc 0, and
               bolding the correct figure silently dropped the row from the check.
  `imperative`
            -- rewrite a backticked counter WRITER (`counts \\`x\\``) into the pseudocode fences' idiom
               (`count x`: the bare name after the bare imperative verb), which is the class "a
               counter's only writer is spelled the way the fences spell one".  card#9320 is why
               this exists: G8's writer idiom required the backtick spelling and a closed verb set,
               so a § 7.2 counter written only as § 8.3's fence writes one reded G8's reverse leg as
               "nothing increments it" -- and, the harm, an undeclared one written there shipped
               green.  It is a HOLD plant (below): the correct verdict is that the writer is SEEN.
  `unwrite` -- rewrite the same writer's verb into one that writes nothing (`names \\`x\\``), which is
               the class "a § 7.2 counter lost its writer".  Also card#9320, and paired with the
               `imperative` hold on the SAME anchor: this plant reds only while the anchored writer
               is the counter's sole writer, which is what keeps that hold from going vacuous.
  `noun`    -- append English that uses "count" as a NOUN beside the counter name to a writer it
               keeps (`increments \\`x\\`, and keeps a count of \\`x\\``), which is the class "the
               widened idiom reads English as a counter write".  Also card#9320, and also a HOLD: the
               imperative verb had to enter G8's idiom, and the word is ordinary English all over the
               document.
  `backtick` -- put backticks round every counter name the anchored span writes BARE after a
               counting verb (`count x` becomes count, a space, then x in backticks), which is the
               class "the widened idiom decayed back into the backtick-only one it replaced".
               card#9320 round 2 is why this exists: G8's CONTROL reds when no counter write in the
               document is spelled bare, and until this plant nothing had seen that control fire.
               The anchor brackets § 8.3's handler fence, the surface that writes counters bare.
               The verb list is restated from G8's idiom because that verifier cannot be imported
               without running it; a bare write this plant cannot rewrite -- another verb, another
               word order, or one outside the fence -- leaves the control silent and turns THIS
               plant red, never green.
  `drop`    -- delete the anchored span outright, which is the class "a declaration a gate holds
               the document to was left out".  card#7341 is why this exists: a Build bullet that
               replayed a fixture and left the harness out of its `Reads:` clause stood below the
               step that builds the harness, and G5 could not see it.  The span is read out of the
               document, so this kind carries nothing it deletes.
  `overlap` -- move the anchored rect's `x` onto the span of the rect before it (`x + width - 1`
               of that rect, both read out of the file), which is the class "two map objects the
               gate holds pairwise disjoint share a pixel".  PR #227 round 2 (card#7341 rows
               14-16) is why this exists: G8's plant was a `bump` of the first object's width,
               which reds only while the two objects touch, and the shipped default already
               carries a gutter between two of its objects -- so a re-authored default with a
               gutter everywhere would have failed this harness on a correct map.  Moving the
               neighbour onto the span reds at any gutter, and the plant still stores no figure.
  `shrink`  -- -1 to a figure the verifier holds AT LEAST some other figure, which is the class "a
               stored size fell under the bound the gate reads it against".  card#7341 row 14's
               slice B is why this exists: G8 holds every `desks` object of the shipped default at
               least the furniture box, and a `bump` there would widen an object that is already
               large enough — the defect is the object getting SMALLER, by one pixel, which is the
               boundary the check states.
  `nonascii-digit`
            -- replace the anchored figure's LAST digit with its Arabic-Indic form (U+0660 + d), which
               is the class "a figure the gate reads with `\\d` is not the ASCII digit its other reader
               requires".  PR #232 round 2 (MINOR-B) is why this exists: G8 compiled `FurnitureBox`'s
               PCRE with Python's Unicode `\\d`, so `4٤0` matched, int()'d to 440 and passed the gate
               while PHP's ASCII `\\d` refused the same file.  The LAST digit, because the shape's
               first is `[1-9]`, ASCII-only in both engines: a plant there reds with or without the
               binding and discriminates nothing (round 3, F1).
  `php-escape`
            -- insert `\\\\` (a PHP single-quoted literal's escape for one backslash) into the anchored
               PCRE, which is the class "the literal's bytes are not the pattern's, and a translation
               that reads the literal compiles a pattern PHP never ran".  PR #232 round 3 (F2) is why
               this exists: G8 reads `FurnitureBox::DECLARATION` out of the PHP source and refuses the
               two escapes it cannot pass through byte-for-byte, and until this plant nothing had seen
               that control fire.
  `instrument`
            -- replace the bold artifact name in the anchored span with the first gate Appendix B's
               Artifact cells name (a bold name whose head noun is `gate` / `gates`), which is the
               class "a declaration a gate holds the document to was swapped for one it exempts".
               PupFuzz/mezzanine#164's round-1 review is why this exists: G5 checked the instrument
               exemption before the harness classification, so a fixture-replaying bullet that named
               a step-0 gate in place of the harness passed.  Round 2 is the second plant of this
               kind: a test that names no fixture left the harness class by the same swap and was
               covered by a gate whose Appendix B row does not gate it.  The gate's name is read out
               of Appendix B on every run; the recognizer restates G5's `gates?` head-noun rule because that
               verifier cannot be imported without running it.
  `separator`
            -- replace the first hyphen of the anchored name with an underscore, which is the class
               "a name was malformed into a shape the gate's token does not read".  Also #164 round 1:
               G5's undeclared-fixture control read only `fx-` names, so `fx_gap` was invisible to it.
  `embolden`
            -- wrap the anchored span in `**`, which is the class "a status marker was written in
               the form a parse reads as a name".  card#7341 Q9 is why this exists: G5 registers
               every bold span in an Appendix B Artifact cell as an artifact, so three bold
               `✅ LANDED` markers each minted a phantom artifact, and a fourth row was written
               unbolded to dodge it -- two forms of one marker.  The plant emboldens a marker
               already in the one form, so what reds is the emphasis alone.

TWO VERDICTS.  `PLANTS` must RED, as described above.  `HOLDS` must NOT: the mutant must carry no
line containing the named substring that the control lacks -- the same differential, pointed the
other way -- and must neither crash nor exit above the control, because a run that died before
judging carries no such line either.  A HOLD is not a pass that cannot fail: each one reds against
a specific wrong gate, and the comment on each entry names that gate.  The `imperative` hold reds
against the backtick-only idiom G8 had before card#9320; the `noun` hold reds against the naive
widening that makes the backtick optional and admits any word after the verb.  Each hold also
declares the premise its verdict rests on, and the harness checks that declaration before any
verifier runs (see `HOLDS`).

An anchor matching NOTHING is a hard error, never a skip -- that is the false-clean shape this whole
directory exists against.  No kind writes the value it perturbs into this file.
"""

import pathlib
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parent.parent.parent

# (verifier, document, anchor, kind, what the plant is, a substring the RED must carry)
#
# The anchor's group(1)/group(3) bracket the thing in group(2); only group(2) is rewritten, so each
# regex has to pin enough context to be unique.  `serializes to **N B**. At` is pinned that far
# because § 6.14 carries a second `serializes to **N B ...` figure that this plant must not hit.  A
# `rename` anchor pins the SURROUNDING WORDS and never the name: the name is read out of group(2),
# so renaming the message legitimately moves the plant instead of stranding it.
PLANTS = [
    (
        "verify-event-schema.py",
        "docs/design/EVENT-SCHEMA.md",
        r"(serializes to \*\*)([\d,]+)( B\*\*\. At)",
        "bump",
        "§ 10.3's stated size of the § 6.14 heartbeat example, which check 10 re-measures by "
        "re-serializing that example",
        "disagrees with",
    ),
    (
        # card#9296.  § 3.1's roster resolution order is a cross-repository contract, and reading
        # only the home path is what made `disagreed` unreachable on a multi-agent install.  Check
        # 11 re-derives the sites from § 3.1's list; renaming the first leaves every other mention
        # of it naming a location the list no longer declares, and the check must say so.
        "verify-event-schema.py",
        "docs/design/EVENT-SCHEMA.md",
        r"(1\. \*\*`)(\$COORD_CONFIG)(`\*\*, whenever it is set)",
        "rename",
        "the first site of § 3.1's roster resolution order, which check 11 holds AT-27, § 18.13 "
        "row 6 and every roster-location mention in D1 against (card#9296)",
        "is not a site of",
    ),
    (
        # card#9296 round 3.  Check 11's DELIVERY leg re-derives the flusher's start paths from
        # § 2.3's list labels and holds § 3.1's DECLARED leg and AT-27 to naming each one; the
        # supervised start was the path the contract had left out.  Renaming the label moves the
        # population, so the two surfaces no longer name a start path § 2.3 declares, and the
        # check must say so.  (Deleting the name from the DECLARED leg itself is a hand proof: a
        # `rename` appends to a word and leaves the old one a substring.)
        "verify-event-schema.py",
        "docs/design/EVENT-SCHEMA.md",
        r"(\n1\. \*\*Supervised )(start)(\*\* — the flusher is registered)",
        "rename",
        "the label of § 2.3's supervised start, which check 11 holds § 3.1's roster delivery "
        "contract and AT-27 to naming (card#9296 round 3)",
        "never names § 2.3's flusher start path",
    ),
    (
        # card#9296 round 3.  § 6.14 states the protocol agent name's bound as a figure so the
        # ingest can refuse by it, which makes it a restatement of § 18.6's; check 12 guards it.
        "verify-event-schema.py",
        "docs/design/EVENT-SCHEMA.md",
        r"(verbatim; ≤ )(48)( B — the bound \[§ 18\.6\])",
        "bump",
        "§ 6.14's `protocol_agent_name` byte bound, which check 12 holds equal to the bound § 18.6 "
        "gives a protocol agent name (card#9296 round 3)",
        "bounds a protocol agent name at",
    ),
    (
        # card#9296 round 4.  Check 12's population grew to every home of that bound.  D2's column is
        # the review's own mutant: narrowed, every gate stayed green while the ingest went on
        # accepting names the store could not hold.  One plant per new home, because each home is
        # read by its own parser and one plant proves one parser.
        "verify-event-schema.py",
        "docs/design/FLEET-STATE.md",
        r"(\n  protocol_agent_name +VARCHAR\()(\d+)(\))",
        "bump",
        "D2 § 6.4's `protocol_agent_name` column width, which check 12 holds equal to § 18.6's bound "
        "(card#9296 round 4)",
        "bounds a protocol agent name at",
    ),
    (
        "verify-event-schema.py",
        "docs/design/FLEET-STATE.md",
        r"(\| `protocol_agent_name` \| slug \| \*\*yes\*\* \| ≤ )(\d+)( B)",
        "bump",
        "D2 § 8.2.1's `protocol_agent_name` byte bound, the same check-12 equality on the read surface "
        "(card#9296 round 4)",
        "bounds a protocol agent name at",
    ),
    (
        "verify-event-schema.py",
        "server/database/migrations/2026_09_13_000000_add_protocol_agent_name_columns_to_seat_state.php",
        r"(string\('protocol_agent_name', )(\d+)(\))",
        "bump",
        "the store migration's `protocol_agent_name` width, the check-12 equality's code home "
        "(card#9296 round 4)",
        "bounds a protocol agent name at",
    ),
    (
        # card#9296 round 4 review.  The store's width is what the LAST migration's `up()` leaves, so
        # a later narrowing whose `down()` restores the old width must red -- it passed while the
        # check read the last match in the file, which was `down()`'s.
        "verify-event-schema.py",
        "server/database/migrations/2026_09_13_000000_add_protocol_agent_name_columns_to_seat_state.php",
        r"(string\('protocol_agent_name', )(\d+)(\))",
        "narrow-up",
        "a later migration narrowing `protocol_agent_name` in `up()` and restoring it in `down()`, "
        "which check 12 must read from `up()` alone (card#9296 round 4 review)",
        "bounds a protocol agent name at",
    ),
    (
        # The same narrowing in raw SQL, a form check 12 does not parse.  It must refuse the file,
        # never pass it on the width an earlier migration gave.
        "verify-event-schema.py",
        "server/database/migrations/2026_09_13_000000_add_protocol_agent_name_columns_to_seat_state.php",
        r"(string\('protocol_agent_name', )(\d+)(\))",
        "narrow-sql",
        "a later migration narrowing `protocol_agent_name` through a raw `ALTER TABLE ... MODIFY`, "
        "which check 12 must refuse as a form it cannot read (card#9296 round 4 review)",
        "in a form this check cannot read",
    ),
    (
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(State-changing events per seat-day at the ceiling:.*?= \*\*)([\d,]+)(\*\*)",
        "bump",
        "§ 8.3's stated per-seat-day event total, which G3 re-ADDS from its seven named components",
        "and states",
    ),
    (
        # card#9303.  § 8.4's snapshot-then-deltas block is PSEUDOCODE -- a fence, bare names, the
        # artifact an implementer copies -- and until this card G7's population could not see into
        # it.  Renaming the message named there is the exact defect that ran green: the server then
        # emits a type no client has a handler for, against a table that never declared it.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(BUFFERS every )([a-z]+\.[a-z_]+)( it receives)",
        "rename",
        "the message name in § 8.4's protocol FENCE, which G7 holds against § 8.3's declared table",
        "is used as a feed message type and has no row in",
    ),
    (
        # card#9320.  G7's defect, one gate over: § 8.3's handler fence writes its counter as a bare
        # name after a bare imperative verb, and G8's writer idiom could see neither half, so a
        # renamed counter there shipped at rc=0 with no row in § 7.1 or § 7.2.  Placed inside the
        # fence for the same reason the G7 plant above is: in backticked prose it would red against
        # the narrow idiom too, and prove nothing about the surface.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(yield feed\.close\{reason:\"stalled\"\} --[^\n]*\n\s*count )([a-z_]+)(; return)",
        "rename",
        "the counter § 8.3's handler FENCE writes in the bare-imperative idiom, which G8's forward "
        "leg holds against § 7.1 / § 7.2 (card#9320)",
        "is written as a counter and has no row in section",
    ),
    (
        # card#9320.  The `imperative` hold below proves G8's reverse leg reads the fence idiom only if
        # the writer it rewrites is the counter's ONLY writer outside § 7.2 and § 11 -- with a second
        # one the hold passes whatever the idiom does.  This plant takes that writer away on the SAME
        # anchor, and reds only while no other writer exists, so a second writer turns THIS red
        # instead of leaving the hold silently proving nothing.  Re-pin both together.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(Every snapshot this plane answers )(counts `[a-z_]+`)(\n\(\[§ 7\.2\])",
        "unwrite",
        "the sole writer of the § 7.2 counter the `imperative` hold rewrites, which G8's reverse leg "
        "must then report as written by nothing (card#9320)",
        "no rule outside that table names",
    ),
    (
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(a gap over \*\*)(\d+)( s\*\* ends the stream)",
        "bump",
        "§ 8.5's stream stall bound, which G3 holds equal to § 8.3's dead-feed figure and one "
        "heartbeat below § 6.7's `feed_outbox` retention (card#9287)",
        "ends a stalled stream at",
    ),
    (
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(\| `feed_outbox` \| \*\*)(\d+)( s\*\* after `created_at`)",
        "bump",
        "\u00a7 6.7's `feed_outbox` retention, the OTHER side of the same G3 equality -- planted "
        "separately because one plant proves one direction of it discriminates, not both (card#9287)",
        "retains `feed_outbox` for",
    ),
    (
        # card#9326.  § 8.5's stall bound has ONE statement, and every other site points at it.  Two
        # copies cannot point and are held to it by G3: § 8.3's handler fence, which is what an
        # implementer builds, and § 12's number-table row.  A fence copy that drifts is the defect the
        # review rounds found in prose, arriving on the surface that ships.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(if now - tick_started > )(\d+)( s:)",
        "bump",
        "§ 8.3's handler-fence copy of § 8.5's stall bound, which G3 holds to its owner (card#9326)",
        "handler fence states the stall bound as",
    ),
    (
        # card#9326.  § 12's row, drifted as written (unbolded) ...
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(\| Stream stall bound \| )(\d+)( s \|)",
        "bump",
        "§ 12's `Stream stall bound` row, drifted unbolded, which G3 holds to § 8.5 (card#9326)",
        "row states the stall bound as",
    ),
    (
        # ... and drifted AND bolded, the shape the review reproduced passing at rc 0 against a match
        # that only read an unbolded figure.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(\| Stream stall bound \| )(\d+ s)( \|)",
        "bold-bump",
        "§ 12's `Stream stall bound` row, drifted and bolded, which G3 still reads (card#9326)",
        "row states the stall bound as",
    ),
    (
        # card#9326.  The row's CONTROL: a renamed row is a copy nothing reads, and the gate must say so
        # rather than hold nothing and report clean.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(\| Stream stall )(bound)( \| )",
        "rename",
        "the label of § 12's `Stream stall bound` row, whose absence G3's CONTROL reports (card#9326)",
        "section 12 has no `Stream stall bound` row",
    ),
    (
        # card#9326.  § 9 case (a)'s two figures are the enforcement bound's one statement, and G14
        # re-derives both: the *under* figure as the handler's re-check interval plus § 8.5's stall
        # bound, the draining figure as that interval plus the handler's tick.  One plant per figure,
        # because each is a separate equality.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(enforcement lag is\s+\*\*under )(\d+)( s\*\*)",
        "bump",
        "§ 9 case (a)'s *under* figure, which G14 re-derives from § 8.3's re-check interval plus "
        "§ 8.5's stall bound (card#9326)",
        "states the enforcement bound as under",
    ),
    (
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(on a client that drains promptly it is \*\*)(\d+)( s \+ one \d+ ms tick\*\*)",
        "bump",
        "§ 9 case (a)'s draining figure, which G14 re-derives from § 8.3's re-check interval and "
        "tick (card#9326)",
        "states the enforcement bound on a draining client as",
    ),
    (
        # card#9326.  G12b's population is the `feed.close` row's DECLARED member set, read off the
        # row.  Renaming a member there is a member the handler fence does not write, and G12b must
        # say so -- it used to hold one member by name and would have waited for this one to be typed.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(\| `feed\.close` \| server → client \|.*?; `)(reload)(` — )",
        "rename",
        "a member of § 8.3's declared `feed.close` set, which G12b now re-derives from that row and "
        "holds § 8.3's handler fence to (card#9326)",
        "G12b: section 8.3's `feed.close` row declares",
    ),
    (
        # card#9296.  The declared protocol agent name is ONE value set with THREE homes across TWO
        # documents -- D1's field table, D2's `ENUM`, D2's read surface -- and card#7957's finding
        # was that NO ACT FAILED when the two identity surfaces disagreed.  The plant renames a
        # member on the STORE's side, which is the surface a migration touches and a document read
        # does not, and G13 must say so rather than leaving a state one surface can emit and
        # another cannot hold.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(protocol_agent_name_check ENUM\('checked','unchecked','disagreed',')([a-z_]+)(')",
        "rename",
        "a member of the declared agent-name check's value set in \u00a7 6.4's `ENUM`, which G13 holds "
        "against D1's own declaration and against \u00a7 8.2.1's row (card#9296)",
        "One value set, three homes, two documents",
    ),
    (
        # card#9296, G13's SECOND leg -- the refusal that no coordination object names a desk.  Its
        # forbidden set is re-derived from § 8.2.1's own binding sentence, so the shape that would
        # make it report clean over everything is that sentence moving out from under the reader.
        # This plant is therefore aimed at the CONTROL rather than at the forbidden-field check: a
        # `rename` there must make the gate SAY it can no longer read its population, never pass.
        # Adding a desk field to § 8.3.3 -- the defect the leg exists for -- is not expressible as a
        # `bump` or a `rename` (neither mutation can write the word `desk` into a field name), so it
        # stays a HAND proof (card#9296: a `coord_thread.desk`, a `coord_round.from_seat`, a nested
        # `…[].seat_ref` and an unparseable row, each red under the shape leg or the control and
        # each green under the name-equality check it replaced).  The limit is
        # stated here rather than left to be inferred from a plant list.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(\*\*`install_id` and `seat_id` are the seat→)(desk)( binding)",
        "rename",
        "§ 8.2.1's declaration of WHICH members are the seat→desk binding, which G13 re-derives the "
        "set no coordination object may name from (card#9296)",
        "no longer declares which members ARE the seat",
    ),
    (
        # card#9296.  G13 leg 2's under-read CONTROL takes its denominator from the § 8.3.3 field
        # tables' own row counts rather than from a written figure, and this plant is what proves
        # it: renaming the `coord.round` table's header column takes that table out of the
        # denominator, so the parsed rows outnumber the declared ones and the control fires.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(`coord\.round` — the post\.\*\*.*?\| )(Field)( \| Type \| Null\? \| Bounds \| Example \|)",
        "rename",
        "the header of § 8.3.3's second field table, which G13's under-read control re-derives its "
        "own denominator from rather than carrying a written count (card#9296)",
        "coordination field rows were read from section 8.3.3's",
    ),
    (
        # card#9320 round 2.  G8's CONTROL is the guard on the widening itself: with every bare write
        # backticked the idiom reaches nothing the backtick-only one could not, and the control must
        # say so rather than report the narrow population as the wide one.
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(\nGET /api/fleet/stream )(.*?)(\n```)",
        "backtick",
        "every bare counter write in § 8.3's handler fence, backticked, which G8's CONTROL must "
        "report as a widening that reaches nothing (card#9320 round 2)",
        "G8 CONTROL: every counter write in this document is backtick-delimited",
    ),
    (
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\| spare \| \*\*)([\d,]+)( B\*\*)",
        "bump",
        "§ 8.1's stated spare bytes, which G3 re-derives as bound minus worst case",
        "re-derived from its own",
    ),
    (
        # card#7341.  G5's harness half: a bullet of a test that replays a fixture declares the
        # harness.  The plant is placed on a half that replays its fixture BY REFERENCE ("the same
        # fixture"), and its substring is the harness-test message, so what it pins is the per-test
        # classification's MESSAGE: a per-bullet fixture match would still red this mutant, through
        # the branch for a bullet whose test names no fixture, no harness and no instrument, but
        # under that branch's message rather than this one.  The plant whose VERDICT depends on the
        # classification is the `instrument` plant below.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\*\*Build — the strip half:\*\* the same fixture, with the status strip rendered\. "
        r"\*\*Reads:\*\*)( \*\*the harness\*\*,)( the)",
        "drop",
        "the harness, removed from the `Reads:` clause of AT-D3-7's strip half, which G5's harness "
        "half must report as a harness-driven bullet that does not declare it (card#7341)",
        "is driven by the harness",
    ),
    (
        # card#7341.  The same half's CONTROL: a fixture name the fixture table does not declare is
        # one the predicate cannot recognise, so the test it drives cannot be classified on it, and
        # G5 must say so rather than classify on what is left.  The suffix `rename` appends puts an
        # underscore in the name, which the fixture closure's own `fx-[a-z0-9-]+` does not read at
        # all -- so this red comes from the harness half's wider token or from nowhere.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(### AT-D3-7 .*?\*\*Build — the protocol half:\*\* replay `)(fx-[a-z0-9-]+)(`)",
        "rename",
        "the fixture AT-D3-7's protocol half replays, renamed to one section 11's fixture table does "
        "not declare, which G5's harness-half CONTROL must report (card#7341)",
        "section 11's fixture table declares no such fixture",
    ),
    (
        # PupFuzz/mezzanine#164 round 1, the MAJOR.  The dodge: AT-D3-1's instrument half replays a
        # fixture, and its `Reads:` clause trades the harness for the gate Appendix B builds first.
        # A gate that checks the instrument exemption before the harness classification passes this
        # mutant at rc 0 -- measured on the first revision -- so this plant's verdict, not only its
        # message, depends on the classification being checked first.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\*\*Build — the instrument half:\*\* replay `[^`]+` alone, then silence; collect the "
        r"animation\s+log\. \*\*Reads:\*\* the \*\*animation log\*\*,)( \*\*the harness\*\*,)( the)",
        "instrument",
        "the harness in AT-D3-1's instrument half swapped for an Appendix B gate, which G5's harness "
        "half must refuse because that test replays a fixture (#164 round 1)",
        "does not stand in for the harness",
    ),
    (
        # PupFuzz/mezzanine#164 round 1, MINOR-2.  The undeclared-fixture CONTROL's token against a
        # name malformed with an underscore.  The fixture closure's own `fx-[a-z0-9-]+` does not read
        # `fx_...`, and this test replays other declared fixtures, so nothing else reds this mutant:
        # against the `fx-`-only token it ran at rc 0.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\*\*Build — the instrument half:\*\* replay `)(fx-[a-z0-9-]+)(` alone)",
        "separator",
        "the fixture AT-D3-1's instrument half replays, its first hyphen made an underscore, which "
        "G5's harness-half CONTROL must report as a fixture the table does not declare (#164 round 1)",
        "section 11's fixture table declares no such fixture",
    ),
    (
        # PupFuzz/mezzanine#164 round 2, the MAJOR.  AT-D3-11 names no fixture, so the harness is in
        # its class only by its own mention of it: swap that mention for a gate and no recognizer
        # sees a harness test any more.  Against the round-1 verifier, which let any instrument
        # cover a non-harness test, this mutant ran at rc 0 -- measured on d62f212.  It reds only
        # because the Appendix B row that builds the gate does not gate AT-D3-11, so the substring
        # is that anchoring's message and the verdict depends on the anchoring alone.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(### AT-D3-11 [^\n]*\n\n- \*\*Build:\*\*(?:(?!\n- \*\*)[\s\S])*?\*\*Reads:\*\*)"
        r"( \*\*the harness\*\*,)( the)",
        "instrument",
        "the harness in AT-D3-11, a test that names no fixture, swapped for an Appendix B gate, which "
        "G5 must refuse because the row that builds the gate does not gate the test (#164 round 2)",
        "an instrument covers only a test that the Appendix B row building it also gates",
    ),
    (
        # card#7341 Q9, G5's marker leg.  Step 3's row carries its landed marker in the one form
        # (unbolded, at the head of the Artifact cell); emboldening it is the shape rows 11-13 had,
        # which G5 read as three artifacts named after a date.  The anchor pins the row number and
        # the marker's shape, never its date or card, so a later marker edit moves the plant with it.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\n\| 3 \| )(✅ landed \d{4}-\d{2}-\d{2} \(card#\d+[^()\n]*\))( — )",
        "embolden",
        "step 3's landed marker in Appendix B, written bold, which G5 must refuse as a phantom "
        "artifact rather than parse into the artifact map (card#7341 Q9)",
        "carries a BOLD status marker",
    ),
    (
        # card#7341's rows 14-16 round (PR #227 round 1, F11), G5's Order-cell leg.  A suffixed row
        # (`8a`) is one the ordering rule cannot compare, and before this leg the parse skipped it
        # silently -- its artifacts unregistered, its gates unenforced, the run reporting clean.
        # The plant suffixes row 14's step.  The substring is the leg's own message: the mutant
        # also reds the ordinary "gated by no Appendix B row" path for the tests row 14 gates, and
        # a red from that path alone would not satisfy it.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\n\| )(14)( \| the \*\*room drawing\*\*)",
        "rename",
        "Appendix B row 14's Order cell suffixed to a non-integer, which G5 must refuse as a row the "
        "ordering rule cannot see rather than skip (card#7341 rows 14-16, PR #227 round 1 F11)",
        "has an Order cell that is not an integer step",
    ),
    (
        # card#7341's rows 14-16 round (PR #227 round 1, F1), G8's disjoint-slots leg.  Section 10.3
        # makes each `desks` object the furniture box and row 14 draws every desk inside it, so the
        # operator's no-overlap ruling rests on the map's objects being pairwise disjoint; this leg
        # holds the shipped default to that.  The plant moves the SECOND object's `x` onto the
        # first's span -- to `x + width - 1` of the first, both read out of the file -- so the two
        # share exactly one pixel column, which is the half-open boundary the check states.
        # ⚠ It is an `overlap` plant and not a `bump` of the first object's width (PR #227 round 2,
        # N5): a one-pixel widening reds only while the two objects TOUCH, and the shipped default
        # already carries a gutter between its sixth and seventh objects, so a re-authoring that
        # parted the first two would have turned that plant into a harness failure on a correct
        # map.  Moving the neighbour onto the span reds at any gutter.  The anchor pins the second
        # `x` after the layer's name and not its value, so a re-authored default moves the plant.
        "verify-floor.py",
        "resources/floor/default.tmj",
        r"(\"desks\"[\s\S]*?\"x\":\d+[\s\S]*?\"x\":)(\d+)(,)",
        "overlap",
        "the shipped default's second `desks` object moved onto the first's span so that the two "
        "share a pixel column, which G8 must refuse as two slots sharing a pixel (card#7341 rows "
        "14-16, PR #227 round 1 F1, round 2 N5)",
        "share a pixel",
    ),
    (
        # card#7341 row 14, slice B — G8's furniture-box leg (G8e).  Section 10.3 states the box at
        # the cap as a sentence and `resources/floor/furniture-box.js` declares it as data; G4 binds
        # section 12's row to the sentence, and this leg binds the sentence to the file.  Bumping the
        # sentence's width is the class "the document's copy drifted from the one source".
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(The furniture box at the cap is )(\d+)( px wide)",
        "bump",
        "§ 10.3's stated furniture-box width, which G8 holds against the one declaration line of "
        "`resources/floor/furniture-box.js` (card#7341 row 14, slice B)",
        "states the furniture box at the cap is",
    ),
    (
        # The same leg's second sentence: the shipped default's pixel size, which section 12's
        # viewport arithmetic is restated on.  Bumping it is the class "the map was re-authored and
        # the prose was not" — the class the 1,608 px figure sat in for a revision.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(The shipped default's grid is )([\d,]+)( px wide)",
        "bump",
        "§ 10.3's stated pixel width of the shipped default, which G8 holds against `width × "
        "tilewidth` read out of `resources/floor/default.tmj` (card#7341 row 14, slice B)",
        "states the shipped default's grid is",
    ),
    (
        # The same leg over the FILE: every `desks` object of the shipped default at least the box.
        # The first object's width is shrunk by one pixel — the boundary the check states — which is
        # the state every object of the default shipped in until slice B, here planted on one.  The
        # anchor pins the first `width` after the layer's name and not its value, so a re-authored
        # default moves the plant.
        "verify-floor.py",
        "resources/floor/default.tmj",
        r"(\"desks\"[\s\S]*?\"width\":)(\d+)(,)",
        "shrink",
        "the shipped default's first `desks` object one pixel narrower than the furniture box, which "
        "G8 must refuse as an object smaller than the box (card#7341 row 14, slice B)",
        "smaller than the furniture box at the cap",
    ),
    (
        # The same check's OTHER axis (PR #232 round 1, MINOR-7): a `≥ box` test is two comparisons,
        # and a plant on the width alone would pass a check that had dropped the height.  The first
        # `height` after the layer's name is the first object's, in Tiled's own key order.
        "verify-floor.py",
        "resources/floor/default.tmj",
        r"(\"desks\"[\s\S]*?\"height\":)(\d+)(,)",
        "shrink",
        "the shipped default's first `desks` object one pixel shorter than the furniture box, which "
        "G8 must refuse as an object smaller than the box on its height (PR #232 round 1, MINOR-7)",
        "smaller than the furniture box at the cap",
    ),
    (
        # PR #232 round 2, MINOR-B — G8e reads the box with the DECLARATION's `\d` bound to ASCII, as
        # PHP's is.  The plant's digit is the width's LAST one, and the position is the whole plant
        # (round 3, F1): the DECLARATION's first digit is `[1-9]`, an ASCII-only range in either
        # engine, so a plant on it reds with or without `re.ASCII` and proves nothing about the
        # binding — while a trailing Arabic-Indic digit is matched by Python's Unicode `\d`, int()'d
        # to the ASCII figure and passed, and is refused only under `re.ASCII`.  Seen both ways in
        # round 4: with `re.ASCII` removed from G8e this plant runs GREEN and the harness reds on it.
        "verify-floor.py",
        "resources/floor/furniture-box.js",
        r"(export const FURNITURE_BOX = Object\.freeze\(\{ width: )(\d+)(, height:)",
        "nonascii-digit",
        "the furniture box's width written with an Arabic-Indic digit, which G8 must refuse as a box "
        "declared in no admitted shape rather than read as the ASCII figure (PR #232 round 2, MINOR-B)",
        "times in the one shape",
    ),
    (
        # PR #232 round 3, F2 — G8e's control over the shape it TRANSLATES.  The DECLARATION is a PHP
        # single-quoted literal, and `\\` is one of the two escapes whose bytes are not the pattern's;
        # the control refuses it by name rather than compile a pattern that is not PHP's.  The anchor
        # pins the constant's name and the pattern's first token, so a re-authored pattern moves it.
        "verify-floor.py",
        "server/app/Floor/FurnitureBox.php",
        r"(private const DECLARATION = '/\^export const FURNITURE_BOX = )(Object)(\\\.freeze)",
        "php-escape",
        "the `\\\\` escape written into `FurnitureBox::DECLARATION`, which G8's control must refuse as a "
        "literal whose bytes are not the PCRE's rather than translate (PR #232 round 3, F2)",
        "G8 CONTROL: `FurnitureBox::DECLARATION` is",
    ),
    (
        # PR #232 round 1, MAJOR-2 — G8f, section 12's viewport arithmetic.  Three figures the cell
        # states are each recomputed from the map, the box and the viewport floor; each plant bumps
        # one so the other two cannot cover for it.  The row count first.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\| Floor viewport floor \|[^\n]*?\*\*)(\d+)( rows? of \d+ furniture boxes:)",
        "bump",
        "the row count the viewport cell states, which G8 re-derives from the shipped default's `desks` "
        "objects grouped by `y` (PR #232 round 1, MAJOR-2)",
        "viewport cell states rows =",
    ),
    (
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\| Floor viewport floor \|[^\n]*?furniture boxes: \d+ × [\d,]+ px = )([\d,]+)( px)",
        "bump",
        "the desk-across product the viewport cell states, which G8 re-derives as boxes per row × the "
        "furniture box's width (PR #232 round 1, MAJOR-2)",
        "viewport cell states desk across =",
    ),
    (
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\| Floor viewport floor \|[^\n]*?fit zoom is \*\*[\d,]+ ÷ [\d,]+ ≈ 0\.)(\d+)(\*\*)",
        "bump",
        "the fit zoom the viewport cell states, which G8 re-derives as the viewport floor over the shipped "
        "default's grid width (PR #232 round 1, MAJOR-2)",
        "states a fit zoom of",
    ),
    (
        # card#7341 step 3, G13.  § 2.3 row 5 makes a HELD seat the client cannot confirm render the
        # empty chair, and `idle`'s Never cell forbade exactly that in absolute terms.  The drop
        # takes the QUALIFICATION and leaves the explanation that follows it — which is the shape a
        # looser check cannot see: the remaining sentence still says "unconfirmed", so a gate
        # greping the cell for `confirm` stays green over the edit it exists to catch.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(never as an empty desk\*\* \(\[§ 7\.5\]\(#75-what-a-degraded-desk-may-never-look-like\)\) )"
        r"(\*\*while the client can confirm the seat\*\* \(\[§ 2\.3\]"
        r"\(#23-membership-a-seat-or-an-install-the-client-does-not-hold\) row 5\))(\. Idle is a)",
        "drop",
        "the scoping of § 7.1's `idle` Never cell to the client's own confirmation, which G13 holds "
        "every cell forbidding the empty desk to carrying (card#7341 step 3)",
        "`idle` Never cell forbids the empty desk unconditionally",
    ),
    (
        # card#7341 step 3, G13.  The same edit on the cell the seat RULED on rather than inherited:
        # `disabled`'s prohibition on looking like `offline` is claimable only on a confirmed read,
        # because a seat the client cannot read is the empty chair whatever flag it last carried.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(shown as `offline` )(\*\*while the client can confirm the seat\*\* \(\[§ 2\.3\]"
        r"\(#23-membership-a-seat-or-an-install-the-client-does-not-hold\) row 5\) )"
        r"(— a seat that is off)",
        "drop",
        "the scoping of § 7.1's `disabled` Never cell to the client's own confirmation (card#7341 "
        "step 3, the ruling on the `disabled`/`offline` collision)",
        "`disabled` Never cell forbids rendering as `offline` unconditionally",
    ),
    (
        # card#7341 step 3, G13's third leg, and the reason the check is not two cells.  Appendix A's
        # U5 restates the off-versus-gone obligation and names § 7.1 as its discharge; with § 7.1
        # scoped and U5 absolute the document publishes both readings at once, and the guard written
        # for this class returned CLEAN over it because it read only § 7.1's own table (pass-6
        # MAJOR 4).  Only the DISCHARGE cell is planted — U5's Obligation cell carries D1's words.
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\| \[§ 7\.1\]\(#71-the-render-per-state\) — )(\*\*which discharges it while the client can "
        r"confirm the seat\*\* \(\[§ 2\.3\]\(#23-membership-a-seat-or-an-install-the-client-does-not-hold\)"
        r" row 5, card#7341 step 3\))(: on every confirmed read)",
        "drop",
        "the scoping of Appendix A's U5 DISCHARGE cell, which names § 7.1 as where D1 § 6.14's "
        "off-versus-gone obligation is discharged while § 7.1 itself holds it only on a confirmed "
        "read (card#7341 step 3, pass-6 MAJOR 4)",
        "Appendix A's U5 states the off-versus-gone obligation as discharged",
    ),
]

# PLANTS' shape plus a premise; the substring is the failure a WRONG gate would print, which the
# mutant must not newly carry.
#
# THE PREMISE.  A hold fails on a mutant rc above the control's, which is sound only while the
# mutant keeps every writer the control has: a mutation that deletes one can red a verifier on a
# counter left with no writer, and that red lands on whichever unrelated sentence happened to be the
# second writer (card#9320 round 3).  So each hold declares exactly one of `adds_only` -- its
# mutation only inserts text, checked here by keeping every character of the control in order -- or
# `premise_plant`, the plant kind that proves what its destructive mutation relies on, which must
# exist in PLANTS.  Neither, or both, fails the harness.
HOLDS = [
    (
        # card#9320, G8's REVERSE leg.  Rewrites the anchored counter's sole writer outside § 7.2 and
        # § 11 into the fence idiom, so the bare-imperative form is its ONLY writer -- the `unwrite`
        # plant on this same anchor is what proves "sole" on every run.  Against the backtick-only
        # idiom this reds "no rule outside that table names it".
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(Every snapshot this plane answers )(counts `[a-z_]+`)(\n\(\[§ 7\.2\])",
        "imperative",
        "a § 7.2 counter whose only writer is the fences' bare-imperative idiom, which G8's reverse "
        "leg must still find (card#9320)",
        "no rule outside that table names",
        {"premise_plant": "unwrite"},
    ),
    (
        # card#9320, G8's FORWARD leg against English.  "count" as a noun in prose, beside a counter
        # name, appended after the writer so the writer stays.  Against a widening that makes the
        # backtick optional and takes any word after the verb, this reds "`of` is written as a
        # counter".
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(exceeds it by more than 1, the server )(increments `[a-z_]+`)( \(\[§ 8\.5\])",
        "noun",
        "prose using \"count\" as an English noun beside a counter name, which G8's forward leg "
        "must not read as a counter write (card#9320)",
        "is written as a counter and has no row in section",
        {"adds_only": True},
    ),
]

# Each reads group(2) out of the document and transforms it; none carries a value of its own.
MUTATIONS = {
    "bump": lambda m: m.group(1) + str(int(m.group(2).replace(",", "")) + 1) + m.group(3),
    "shrink": lambda m: m.group(1) + str(int(m.group(2).replace(",", "")) - 1) + m.group(3),
    "nonascii-digit": lambda m: m.group(1) + m.group(2)[:-1] + chr(0x0660 + int(m.group(2)[-1])) + m.group(3),
    "php-escape": lambda m: m.group(1) + "\\\\" + m.group(2) + m.group(3),
    "bold-bump": lambda m: (m.group(1) + "**"
                            + re.sub(r"^\d+", lambda d: str(int(d.group(0)) + 1), m.group(2)) + "**"
                            + m.group(3)),
    "rename": lambda m: m.group(1) + m.group(2) + "_renamed" + m.group(3),
    "imperative": lambda m: (m.group(1) + "count " + re.search(r"`([a-z_]+)`", m.group(2)).group(1)
                             + m.group(3)),
    "unwrite": lambda m: m.group(1) + re.sub(r"^\w+", "names", m.group(2)) + m.group(3),
    "backtick": lambda m: (m.group(1)
                           + re.sub(r"\b(count|counting|counts|increments|counted)(\s+)"
                                    r"([a-z]*_[a-z_]*)(?![\w`]|\.\w)", r"\1\2`\3`", m.group(2))
                           + m.group(3)),
    "noun": lambda m: (m.group(1) + m.group(2) + ", and keeps a count of "
                       + re.search(r"`[a-z_]+`", m.group(2)).group(0) + m.group(3)),
    "drop": lambda m: m.group(1) + m.group(3),
    # The previous object's `x` and `width` are the first of each after the layer's name in
    # group(1); `x + width - 1` is the last pixel column of its half-open span.
    "overlap": lambda m: (m.group(1)
                          + str(int(re.search(r'"x":(\d+)', m.group(1)).group(1))
                                + int(re.search(r'"width":(\d+)', m.group(1)).group(1)) - 1)
                          + m.group(3)),
    "instrument": lambda m: (m.group(1)
                             + re.sub(r"\*\*[^*]+\*\*", lambda _: "the **" + re.search(
                                 r"^\| \d+ \|[^|\n]*?\*\*([^*\n]*\bgates?)\*\*",
                                 m.string[m.string.index("| Order | Artifact | Gate |"):],
                                 re.M).group(1) + "**", m.group(2), count=1)
                             + m.group(3)),
    "separator": lambda m: m.group(1) + m.group(2).replace("-", "_", 1) + m.group(3),
    "embolden": lambda m: m.group(1) + "**" + m.group(2) + "**" + m.group(3),
}

# The spawning kinds.  Each reads the column, its width and its table out of the anchored migration
# and returns the (up, down) statements of a LATER migration that narrows the column by one byte.
# The table is the anchored file's `Schema::table(...)`, and the column is the quoted name in the
# anchor's group(1).  Neither is written here.
SPAWN_NAME = "9999_12_31_235959_selftest_plant_narrows_a_column.php"


def _spawned(m):
    column = re.search(r"'(\w+)'", m.group(1)).group(1)
    table = re.search(r"Schema::table\(\s*'(\w+)'", m.string).group(1)
    return column, table, int(m.group(2))


def _builder(table, stmt):
    return f"Schema::table('{table}', function (Blueprint $table) {{\n            $table->{stmt};\n        }});"


def _narrow_up(m):
    _, table, width = _spawned(m)
    return (_builder(table, m.group(1) + str(width - 1) + m.group(3) + "->nullable()->change()"),
            _builder(table, m.group(1) + str(width) + m.group(3) + "->nullable()->change()"))


def _narrow_sql(m):
    column, table, width = _spawned(m)
    sql = "DB::statement(\"ALTER TABLE {} MODIFY {} VARCHAR({}) CHARACTER SET ascii COLLATE ascii_bin NULL\");"
    return sql.format(table, column, width - 1), sql.format(table, column, width)


SPAWNS = {"narrow-up": _narrow_up, "narrow-sql": _narrow_sql}

MIGRATION_TEMPLATE = """<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        %s
    }

    public function down(): void
    {
        %s
    }
};
"""


def tracked_files():
    """The tracked population, read from git rather than walked: a walk would sweep in
    `__pycache__`, editor droppings and anything else untracked, none of which CI checked out."""
    out = subprocess.run(
        ["git", "-C", str(ROOT), "ls-files", "-z"],
        capture_output=True, text=True, check=True,
    ).stdout
    return [f for f in out.split("\0") if f]


FILES = tracked_files()
if len(FILES) < 100:
    sys.exit(f"CONTROL: only {len(FILES)} tracked files enumerated — the population this harness "
             f"copies is wrong, and every verdict below would be about a tree that is not the "
             f"checkout")


def anchored(text, rel, anchor, kind):
    """Find `anchor` in `text` and apply `kind`'s rewrite there.

    Returns (match, mutated text); a spawning kind leaves the text as it is and returns its match.
    Raises on an anchor that matches nothing.
    """
    if kind in SPAWNS:
        m = re.search(anchor, text, flags=re.S)
        new, n = text, 1 if m else 0
    else:
        m = None
        new, n = re.subn(anchor, MUTATIONS[kind], text, count=1, flags=re.S)
    if n != 1:
        raise SystemExit(
            f"CONTROL: the plant's anchor matched {n} times in {rel} — this harness would "
            f"then report a verifier as PROVEN on a defect it was never shown.  The "
            f"document moved under the anchor; re-pin it.\n  anchor: {anchor}")
    return m, new


def lost_from(control, mutant):
    """Where the mutant fails to keep every character of the control in order, as (line, the
    control text it dropped or replaced); None when the mutant only inserts.  The common head and
    tail are stripped first, so the report names the changed span rather than the whole document."""
    head, limit = 0, min(len(control), len(mutant))
    while head < limit and control[head] == mutant[head]:
        head += 1
    tail = 0
    while tail < limit - head and control[-1 - tail] == mutant[-1 - tail]:
        tail += 1
    old, new = control[head:len(control) - tail], iter(mutant[head:len(mutant) - tail])
    if all(c in new for c in old):
        return None
    return control[:head].count("\n") + 1, old


def run_verifier(tool, mutation=None):
    """Copy the tracked tree to a scratch root, optionally plant one defect, run `tool` there.

    Returns (returncode, combined output).  Raises on an anchor that matches nothing.
    """
    with tempfile.TemporaryDirectory() as td:
        tmp = pathlib.Path(td)
        for rel in FILES:
            src = ROOT / rel
            if not src.is_file():          # submodule gitlinks and the like
                continue
            dst = tmp / rel
            dst.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy(src, dst)

        if mutation is not None:
            rel, anchor, kind = mutation
            doc = tmp / rel
            m, new = anchored(doc.read_text(encoding="utf-8"), rel, anchor, kind)
            if kind in SPAWNS:
                spawned = doc.with_name(SPAWN_NAME)
                if spawned.exists():
                    raise SystemExit(f"CONTROL: {spawned.name} already exists beside {rel}, so the "
                                     f"plant would overwrite a tracked file rather than add one")
                spawned.write_text(MIGRATION_TEMPLATE % SPAWNS[kind](m), encoding="utf-8")
            else:
                doc.write_text(new, encoding="utf-8")

        proc = subprocess.run(
            [sys.executable, str(tmp / "tools" / "design" / tool)],
            capture_output=True, text=True,
        )
        return proc.returncode, proc.stdout + proc.stderr


# Every hold's premise, checked before any verifier runs: a hold whose premise is undeclared or
# false has no verdict worth waiting for.
plant_kinds = {p[3] for p in PLANTS}
premise_failures = []
for tool, rel, anchor, kind, what, wrong, *premise in HOLDS:
    name = f"hold [{kind}] ({tool}, {rel})"
    premise = premise[0] if premise else {}      # a deleted declaration declares neither
    declared = sorted({"adds_only", "premise_plant"} & set(premise))
    if len(declared) != 1:
        premise_failures.append(
            f"{name} declares {' and '.join(declared) or 'neither adds_only nor premise_plant'} — "
            f"a hold declares exactly one, naming the premise its rc verdict rests on")
    elif declared == ["premise_plant"]:
        if premise["premise_plant"] not in plant_kinds:
            premise_failures.append(
                f"{name} rests on a {premise['premise_plant']!r} plant and PLANTS has no plant of that "
                f"kind, so nothing proves the premise its mutation relies on")
    else:
        control = (ROOT / rel).read_text(encoding="utf-8")
        lost = lost_from(control, anchored(control, rel, anchor, kind)[1])
        if lost:
            premise_failures.append(
                f"{name} declares adds_only and its mutation drops or replaces control text at "
                f"L{lost[0]}: {lost[1][:120]!r}")
if premise_failures:
    raise SystemExit("HOLD PREMISE:\n" + "\n".join(f"  - {f}" for f in premise_failures))

failures = []
print(f"planting against {len(FILES)} tracked files, copied per run\n")

for tool, rel, anchor, kind, what, expect in PLANTS:
    print(f"── {tool}")
    print(f"   plant [{kind}]: {what}")

    ctl_rc, ctl_out = run_verifier(tool)
    mut_rc, mut_out = run_verifier(tool, (rel, anchor, kind))

    # The RED must be attributable to the plant, not to whatever else the tree may be carrying:
    # a verifier that was already red would otherwise "prove" itself on somebody else's defect.
    mut_lines = [l.strip() for l in mut_out.splitlines() if expect in l]
    ctl_lines = [l.strip() for l in ctl_out.splitlines() if expect in l]
    new_lines = [l for l in mut_lines if l not in ctl_lines]

    if mut_rc == 0:
        failures.append(f"{tool}: planted a defect in {rel} and the verifier still exited 0 — "
                        f"the guard this plant targets does not fire, so wiring it into CI buys "
                        f"nothing for that class")
        print("   ✗ mutant exited 0")
    elif not new_lines:
        failures.append(f"{tool}: the mutant red carries no line containing {expect!r} that the "
                        f"control did not already carry — the red is not attributable to the "
                        f"plant, so it is not evidence the plant was caught")
        print(f"   ✗ mutant rc={mut_rc} but the red is not attributable to the plant")
    else:
        print(f"   ✓ control rc={ctl_rc} → mutant rc={mut_rc}, and the red names the plant:")
        print(f"     {new_lines[0][:160]}")
    print()

for tool, rel, anchor, kind, what, wrong, *_ in HOLDS:
    print(f"── {tool}")
    print(f"   hold [{kind}]: {what}")

    ctl_rc, ctl_out = run_verifier(tool)
    mut_rc, mut_out = run_verifier(tool, (rel, anchor, kind))

    # The same differential as above, pointed the other way: a line the control already carries is
    # somebody else's defect, and only a NEW line carrying the wrong gate's failure fails the hold.
    ctl_lines = [l.strip() for l in ctl_out.splitlines() if wrong in l]
    new_lines = [l.strip() for l in mut_out.splitlines() if wrong in l and l.strip() not in ctl_lines]

    # ...and a hold is only a verdict if the mutant run FINISHED judging.  A verifier that crashes on
    # the correct form, or dies on it before printing the failure the substring names, carries no
    # such line either, so the absence alone would pass it (card#9320 round 2).  A crash or an exit
    # code above the control's is the hold's failure, whatever the substring says.
    crashed = "Traceback" in mut_out
    if new_lines:
        failures.append(f"{tool}: a HOLD mutant in {rel} newly carries {wrong!r} — the verifier "
                        f"reds on the correct form this hold plants, so its guard is back to the "
                        f"wrong population: {new_lines[0][:200]}")
        print(f"   ✗ mutant rc={mut_rc} and newly carries: {new_lines[0][:160]}")
    elif crashed or mut_rc > ctl_rc:
        how = "crashed (its output carries a Traceback)" if crashed else "exited above the control"
        last = (mut_out.strip().splitlines() or ["(the mutant run printed nothing)"])[-1].strip()
        failures.append(f"{tool}: a HOLD mutant in {rel} {how}, control rc={ctl_rc} → mutant "
                        f"rc={mut_rc} — the verifier did not accept the correct form this hold "
                        f"plants, and a missing {wrong!r} line from a run that did not finish "
                        f"judging is no evidence it would not have printed one: {last[:200]}")
        print(f"   ✗ control rc={ctl_rc} → mutant rc={mut_rc}, and the mutant {how}: {last[:160]}")
    else:
        print(f"   ✓ control rc={ctl_rc} → mutant rc={mut_rc}, and no new line carries {wrong!r}")
    print()

if failures:
    print("PLANT FAILURES:")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)

print(f"ALL PLANTS CAUGHT — {len(PLANTS)} plants over {len({p[0] for p in PLANTS})} verifiers, "
       f"each seen to red on a defect of a named class "
       f"({', '.join(sorted({p[3] for p in PLANTS}))}), each red attributable to its plant and "
       f"to the guard it names only where the plant is that guard's own discriminant; "
       f"{len(HOLDS)} holds ({', '.join(sorted({p[3] for p in HOLDS}))}), each correct form "
       f"planted and seen NOT to red")

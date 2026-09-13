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
live -- never that the rest of the guard classes those verifiers carry do.  How many plants, over
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
that name moves the plant with it instead of turning this file red.  There are two kinds, and the
kind is named per plant because a gate can only be proven on a defect of its own class:

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

An anchor matching NOTHING is a hard error, never a skip -- that is the false-clean shape this whole
directory exists against.  Neither kind writes the value it perturbs into this file.
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
        r"(\n1\. \*\*Supervised )(start)(\*\* — the installer registers)",
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
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\| spare \| \*\*)([\d,]+)( B\*\*)",
        "bump",
        "§ 8.1's stated spare bytes, which G3 re-derives as bound minus worst case",
        "re-derived from its own",
    ),
]

# Both read group(2) out of the document and transform it; neither carries a value of its own.
MUTATIONS = {
    "bump": lambda m: m.group(1) + str(int(m.group(2).replace(",", "")) + 1) + m.group(3),
    "rename": lambda m: m.group(1) + m.group(2) + "_renamed" + m.group(3),
}


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
            text = doc.read_text(encoding="utf-8")
            new, n = re.subn(anchor, MUTATIONS[kind], text, count=1, flags=re.S)
            if n != 1:
                raise SystemExit(
                    f"CONTROL: the plant's anchor matched {n} times in {rel} — this harness would "
                    f"then report a verifier as PROVEN on a defect it was never shown.  The "
                    f"document moved under the anchor; re-pin it.\n  anchor: {anchor}")
            doc.write_text(new, encoding="utf-8")

        proc = subprocess.run(
            [sys.executable, str(tmp / "tools" / "design" / tool)],
            capture_output=True, text=True,
        )
        return proc.returncode, proc.stdout + proc.stderr


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

if failures:
    print("PLANT FAILURES:")
    for f in failures:
        print(f"  - {f}")
    sys.exit(1)

print(f"ALL PLANTS CAUGHT — {len(PLANTS)} plants over {len({p[0] for p in PLANTS})} verifiers, "
       f"each seen to red on a defect of the class its guard exists for "
       f"({', '.join(sorted({p[3] for p in PLANTS}))}), each red attributable to its plant")

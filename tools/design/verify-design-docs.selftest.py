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

WHAT IT DELIBERATELY DOES NOT ASSERT.  Not coverage: three plants over three verifiers carrying
some fifty guard classes prove those three guards live, never that the rest do.  Nothing here is
evidence about `verify-harness-facts.py` (see the workflow header for why it is unwired) or about
`floor-preview.selftest.mjs` and `floor-preview.browser.mjs`, which carry their own planted
controls internally and need no harness around them.

WHY A COPY OF THE TREE, AND WHY `git ls-files`.  Each verifier derives its root from its own
`__file__`, so judging a mutated document means giving it a mutated TREE -- never editing the
checkout, which on a developer's machine is live work.  The population is the tracked files as
they exist in the WORKING TREE, so the harness judges the bytes CI actually checked out rather
than a revision; 255 files / 4.4 MB makes a per-plant copy cheaper than being clever.  The whole
tree is copied rather than the four documents, because `verify-event-schema.py` resolves path
references out of `docs/VERSIONING.md` and reds on files a partial tree would be missing.

THE PLANTS ARE ARITHMETIC, NOT PROSE.  Each perturbs by +1 a figure the verifier RE-DERIVES --
a serialized size, a re-added sum, a cap subtraction -- so the plant is the exact defect class the
guard exists for (a stated figure drifting from what re-derives it), and the expected red is a
specific sentence rather than any nonzero exit.  No plant stores the figure's VALUE: each is
re-read from the document and incremented, so a legitimate edit to any of the three numbers moves
the plant with it instead of turning this file red.  An anchor matching NOTHING is a hard error,
never a skip -- that is the false-clean shape this whole directory exists against.
"""

import pathlib
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = pathlib.Path(__file__).resolve().parent.parent.parent

# (verifier, document, anchor, what the plant is, a substring the RED must carry)
#
# The anchor's group(1)/group(3) bracket the digits in group(2); only group(2) is rewritten, so
# each regex has to pin enough context to be unique.  `serializes to **N B**. At` is pinned that
# far because § 6.4 carries a second `serializes to **2,700 B ...` that this plant must not hit.
PLANTS = [
    (
        "verify-event-schema.py",
        "docs/design/EVENT-SCHEMA.md",
        r"(serializes to \*\*)([\d,]+)( B\*\*\. At)",
        "§ 10.3's stated size of the § 6.14 heartbeat example, which check 10 re-measures by "
        "re-serializing that example",
        "disagrees with",
    ),
    (
        "verify-fleet-state.py",
        "docs/design/FLEET-STATE.md",
        r"(State-changing events per seat-day at the ceiling:.*?= \*\*)([\d,]+)(\*\*)",
        "§ 8.3's stated per-seat-day event total, which G3 re-ADDS from its seven named components",
        "and states",
    ),
    (
        "verify-floor.py",
        "docs/design/FLOOR.md",
        r"(\| spare \| \*\*)([\d,]+)( B\*\*)",
        "§ 8.1's stated spare bytes, which G3 re-derives as bound minus worst case",
        "re-derived from its own",
    ),
]


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
            rel, anchor = mutation
            doc = tmp / rel
            text = doc.read_text(encoding="utf-8")

            def bump(m):
                return m.group(1) + str(int(m.group(2).replace(",", "")) + 1) + m.group(3)

            new, n = re.subn(anchor, bump, text, count=1, flags=re.S)
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

for tool, rel, anchor, what, expect in PLANTS:
    print(f"── {tool}")
    print(f"   plant: +1 to {what}")

    ctl_rc, ctl_out = run_verifier(tool)
    mut_rc, mut_out = run_verifier(tool, (rel, anchor))

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

print(f"ALL PLANTS CAUGHT — {len(PLANTS)} verifiers each seen to red on a defect of its own "
       f"headline class, each red attributable to its plant")

#!/usr/bin/env python3
"""changelog-fragments.selftest.py — hermetic acceptance for bin/changelog-fragments.py.

`bin/changelog-fragments.py` is the one reader of the per-change changelog notes
(`docs/changelog.d/<card>.md`, card#12935): the release runs it to write the version's section of
`docs/CHANGELOG.md`, and `bin/release-pr-guard.py` imports it to judge a note. A release section
that silently dropped a note, re-ordered differently on a re-run, or kept a note it had already
written would be a release changelog that is wrong with nothing red. So every arm drives the
REAL script as a subprocess over a temp checkout and asserts on the FILES it leaves, each refusal
paired with a control that passes.

§ 4 is the one that ties this to the release record: the section the assembler writes is the
section the old hand-retitle produced, byte for byte, once the bullets are in card order.

NO NETWORK, NO GIT, NO CREDENTIAL.
"""
from __future__ import annotations

import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
TOOL = REPO / "bin" / "changelog-fragments.py"
WORKFLOW = REPO / ".github" / "workflows" / "release-pr-guard.yml"
NOTES = "docs/changelog.d"
PREAMBLE = "# Changelog\n\nSome preamble.\n\n"
PREVIOUS = "## [0.1.0] — 2026-10-01\n\n- **card#1** — the previous release.\n"

fails = 0
checks = 0
DIRS: list[Path] = []


def eq(what: str, want, got) -> None:
    global fails, checks
    checks += 1
    if want == got:
        print(f"  ok   {what}")
    else:
        fails += 1
        print(f"  FAIL {what} — expected {want!r} got {got!r}", file=sys.stderr)


def checkout(changelog: str, notes: dict[str, str | bytes], version: str | None = "0.2.0") -> Path:
    d = Path(tempfile.mkdtemp(prefix="clfrag-"))
    DIRS.append(d)
    (d / "docs").mkdir()
    (d / "docs" / "CHANGELOG.md").write_text(changelog, encoding="utf-8")
    (d / NOTES).mkdir()
    (d / NOTES / "README.md").write_text("# notes\n", encoding="utf-8")
    for name, content in notes.items():
        p = d / NOTES / name
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_bytes(content if isinstance(content, bytes) else content.encode("utf-8"))
    if version is not None:
        (d / "VERSION").write_text(version + "\n", encoding="utf-8")
    return d


def tool(d: Path, *args: str) -> subprocess.CompletedProcess:
    return subprocess.run([sys.executable, str(TOOL), *args, "--repo", str(d)],
                          capture_output=True, text=True)


def note(card: int, text: str = "") -> tuple[str, str]:
    return f"{card}.md", f"- **card#{card}** — {text or f'what card#{card} did.'}\n"


def changelog(d: Path) -> str:
    return (d / "docs" / "CHANGELOG.md").read_text(encoding="utf-8")


def left(d: Path) -> list[str]:
    return sorted(p.name for p in (d / NOTES).iterdir())


EMPTY = PREAMBLE + "## [Unreleased]\n\n" + PREVIOUS
N30, N5, N30B = note(30), note(5), ("30-second.md", "- **card#30** — a second part.\n")

# =============================================================================================
print("== 1. check — every well-formed note passes, every malformed shape is refused by name ==")
d = checkout(EMPTY, dict([N30, N5, N30B]))
r = tool(d, "check")
eq("three well-formed notes and the README → exit 0", 0, r.returncode)
eq("  … counting the notes, not the README", True, "3 fragment(s)" in r.stdout)
for label, name, content, needle in (
        ("a name that is not <card>.md", "card-30.md", "- **card#30** — x.\n", "not a fragment name"),
        ("a leading zero", "030.md", "- **card#30** — x.\n", "not a fragment name"),
        ("an upper-case slug", "30-Second.md", "- **card#30** — x.\n", "not a fragment name"),
        ("two lines", "30.md", "- **card#30** — x.\n- y.\n", "exactly ONE line"),
        ("a trailing blank line", "30.md", "- **card#30** — x.\n\n", "exactly ONE line"),
        ("no final newline", "30.md", "- **card#30** — x.", "exactly ONE line"),
        ("a carriage return", "30.md", "- **card#30** — x.\r\n", "carriage return"),
        ("a hyphen for the em dash", "30.md", "- **card#30** - x.\n", "em dash"),
        ("an empty note", "30.md", "- **card#30** — \n", "em dash"),
        ("indented", "30.md", "  - **card#30** — x.\n", "em dash"),
        ("the bullet naming another card", "30.md", "- **card#31** — x.\n",
         "named for card#30 but its bullet names card#31"),
        ("not UTF-8", "30.md", b"- **card#30** \xe2\x80 x.\n", "not valid UTF-8")):
    d = checkout(EMPTY, {name: content})
    r = tool(d, "check")
    eq(f"{label} → exit 1", 1, r.returncode)
    eq(f"  … naming the file and why ({needle!r})", True,
       f"{NOTES}/{name}" in r.stderr and needle in r.stderr)
d = checkout(EMPTY, {"old/30.md": "- **card#30** — x.\n"})
r = tool(d, "check")
eq("a note in a subdirectory → exit 1, never silently skipped", 1, r.returncode)
eq("  … and says so", True, "never in a subdirectory" in r.stderr)

# =============================================================================================
print("== 2. print — the block, highest card first, then file name; touches nothing ==")
d = checkout(EMPTY, dict([N5, N30B, N30]))
r = tool(d, "print")
eq("print → exit 0", 0, r.returncode)
eq("  … card 30's two notes (30.md, then 30-second.md), then card 5",
   N30[1] + N30B[1] + N5[1], r.stdout)
eq("  … and the checkout is unchanged", (EMPTY, ["30-second.md", "30.md", "5.md", "README.md"]),
   (changelog(d), left(d)))

# =============================================================================================
print("== 3. assemble — the section under [Unreleased], the notes deleted, the README kept ==")
d = checkout(EMPTY, dict([N5, N30B, N30]))
r = tool(d, "assemble", "--date", "2026-11-07")
eq("assemble → exit 0", 0, r.returncode)
eq("  … writing exactly the section, in order, above the previous release",
   PREAMBLE + "## [Unreleased]\n\n## [0.2.0] — 2026-11-07\n\n"
   + N30[1] + N30B[1] + N5[1] + "\n" + PREVIOUS, changelog(d))
eq("  … the version came from VERSION", True, "## [0.2.0]" in changelog(d))
eq("  … every note it used is deleted and the README stays", ["README.md"], left(d))
# --version overrides VERSION.
d = checkout(EMPTY, dict([N5]), version=None)
r = tool(d, "assemble", "--date", "2026-11-07", "--version", "0.3.0")
eq("--version names the section when given", True,
   r.returncode == 0 and "## [0.3.0] — 2026-11-07\n" in changelog(d))
# A changelog with no released section yet: the section ends the file, with no trailing blank.
d = checkout(PREAMBLE + "## [Unreleased]\n", dict([N5]))
r = tool(d, "assemble", "--date", "2026-11-07")
eq("the first release ever ends the file cleanly", PREAMBLE + "## [Unreleased]\n\n"
   "## [0.2.0] — 2026-11-07\n\n" + N5[1], changelog(d))

# --- CATCH-UP: the release branch takes in later `dev` work and runs assemble again with
# --into-existing. The new notes join the SAME section, re-ordered by the same rule.
d = checkout(EMPTY, dict([N5]))
tool(d, "assemble", "--date", "2026-11-07")
(d / NOTES / N30[0]).write_text(N30[1], encoding="utf-8")
(d / NOTES / "3.md").write_text("- **card#3** — late.\n", encoding="utf-8")
before = changelog(d)
r = tool(d, "assemble", "--date", "2026-11-08")
eq("a second run WITHOUT --into-existing is refused (the section might be a shipped one)",
   (1, True, before), (r.returncode, "--into-existing" in r.stderr, changelog(d)))
r = tool(d, "assemble", "--date", "2026-11-08", "--into-existing")
eq("a second run folds the new notes into the same section, re-ordered, with the new date",
   PREAMBLE + "## [Unreleased]\n\n## [0.2.0] — 2026-11-08\n\n" + N30[1] + N5[1]
   + "- **card#3** — late.\n\n" + PREVIOUS, (changelog(d)))
eq("  … and deletes them too", ["README.md"], left(d))
# A re-run with nothing new keeps the section as it is (the date apart).
r = tool(d, "assemble", "--date", "2026-11-08", "--into-existing")
eq("  … and a third run with no new notes changes nothing", (0, 1),
   (r.returncode, changelog(d).count("## [0.2.0]")))

# --- REFUSALS, each with nothing written.
for label, cl, notes, args, needle in (
        ("a line still under [Unreleased]",
         PREAMBLE + "## [Unreleased]\n\n- **card#9** — written by hand.\n\n" + PREVIOUS,
         dict([N5]), ("--date", "2026-11-07"), "only the fragments"),
        ("no [Unreleased] heading", PREAMBLE + PREVIOUS, dict([N5]), ("--date", "2026-11-07"),
         "no `## [Unreleased]` heading"),
        ("no notes and no section", EMPTY, {}, ("--date", "2026-11-07"), "no fragments"),
        ("the version of the PREVIOUS release (VERSION not bumped)", EMPTY, dict([N5]),
         ("--date", "2026-11-07", "--version", "0.1.0"), "a released section is never added to"),
        ("…the same with --into-existing is still never a released section further down",
         PREAMBLE + "## [Unreleased]\n\n## [0.2.0] — 2026-11-07\n\n- **card#9** — x.\n\n"
         + PREVIOUS, dict([N5]), ("--date", "2026-11-07", "--version", "0.1.0", "--into-existing"),
         "not the newest one"),
        ("--into-existing with no section to fold into", EMPTY, dict([N5]),
         ("--date", "2026-11-07", "--into-existing"), "Run without it"),
        ("a malformed note among good ones", EMPTY, {**dict([N5]), "x.md": "- x\n"},
         ("--date", "2026-11-07"), "not a fragment name"),
        ("an existing section carrying prose", PREAMBLE + "## [Unreleased]\n\n## [0.2.0] — "
         "2026-11-07\n\nA paragraph.\n\n" + PREVIOUS, dict([N5]),
         ("--date", "2026-11-07", "--into-existing"),
         "cannot be re-ordered safely")):
    d = checkout(cl, notes)
    before = (changelog(d), left(d))
    r = tool(d, "assemble", *args)
    eq(f"{label} → exit 1", 1, r.returncode)
    eq(f"  … saying why ({needle!r})", True, needle in r.stderr)
    eq("  … and nothing written or deleted", before, (changelog(d), left(d)))
d = checkout(EMPTY, dict([N5]))
r = tool(d, "assemble")
eq("assemble with no --date → exit 2 (usage)", 2, r.returncode)
r = tool(d, "assemble", "--date", "2026-13-01")
eq("assemble with a date that is not a calendar date → exit 2", 2, r.returncode)

# =============================================================================================
print("== 4. THE RELEASE RECORD — the same section the hand retitle produced, byte for byte ==")
# Before card#12935 release flow step 4 retitled `## [Unreleased]` in place: the bullets the
# change PRs had written stayed exactly as they were under the new version heading. A file in
# that old shape, its bullets in card order, is moved into notes (the migration card#12935 made)
# and assembled; the result must equal the old retitle of the same file.
bullets = [note(12935), note(12846, "a **bold** note — with an inner dash."),
           note(12013), note(7)]
old = PREAMBLE + "## [Unreleased]\n\n" + "".join(c for _, c in bullets) + "\n" + PREVIOUS
retitled = old.replace("## [Unreleased]\n\n", "## [Unreleased]\n\n## [0.2.0] — 2026-11-07\n\n", 1)
d = checkout(EMPTY, dict(bullets))
r = tool(d, "print")
eq("the notes print the old [Unreleased] bullets byte for byte",
   "".join(c for _, c in bullets), r.stdout)
tool(d, "assemble", "--date", "2026-11-07")
eq("assembling them gives the old hand-retitled file byte for byte", retitled, changelog(d))

# =============================================================================================
print("== 5. THE REPO'S OWN NOTES are well-formed, and CI runs this file ==")
r = subprocess.run([sys.executable, str(TOOL), "check"], capture_output=True, text=True)
eq("`changelog-fragments.py check` over this checkout → exit 0", 0, r.returncode)
if r.returncode:
    print(r.stderr, file=sys.stderr)
wf = WORKFLOW.read_text(encoding="utf-8")
eq("release-pr-guard.yml runs this selftest", True,
   "python3 bin/changelog-fragments.selftest.py" in wf)

for d in DIRS:
    shutil.rmtree(d, ignore_errors=True)
print(f"\nchangelog-fragments.selftest: {checks - fails}/{checks} passed")
sys.exit(1 if fails else 0)

# Changelog notes waiting for the next release

Each change PR that names a card adds its changelog note here as **one file of its own**, and
never edits `docs/CHANGELOG.md`. Two changes built side by side then touch different files, so
neither conflicts with the other at merge. The release PR gathers every note into the version's
section of `docs/CHANGELOG.md` and deletes the files (`docs/VERSIONING.md § Release flow`, step 4).

**The file.** `docs/changelog.d/<card>.md` — the card number, no leading zero. A further PR on a
card whose first note is still waiting here adds `<card>-<slug>.md` (lowercase letters, digits
and single hyphens), or extends the existing note.

**The content.** Exactly one line, ending in a newline: the bullet exactly as it will appear in
the CHANGELOG, in the same style as the bullets already there.

```
- **card#12935** — **What the installer or user now gets, in one bold sentence.** Then the detail.
```

**Check a note before pushing:** `python3 bin/changelog-fragments.py check`. The same rules are
enforced on every PR by `bin/release-pr-guard.py`: R4 asks for the note of each card the branch or
title names, and R8 refuses a malformed note and any line added under `## [Unreleased]` by hand.
`docs/PLAN.md § 4` owns the rules; `bin/changelog-fragments.py` is the one program that reads them.
This README is not a note and is never assembled.

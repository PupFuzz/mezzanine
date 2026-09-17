# ── VENDORED — DO NOT EDIT THE CODE BELOW THE HEADER ──────────────────────────────────────────
#
# coord_audit_field.py — `review-prep.py`'s audit-trail-row reader, vendored so this repository's
# CI can ask the REAL reader whether a PR body carries `Built:` and `Coordinated in:`, instead of
# asking a regex written here. It hosts TWO upstream pieces and one line of mezzanine's own glue,
# and each is labelled below so a `diff` against a plugin checkout is a mechanical operation.
#
#   upstream repo     PupFuzz/agent-board-framework  (PRIVATE)
#   vendored at       coord v0.52.0 — the version this install runs (`CLAUDE.md`'s managed block)
#
#   piece 1  the `VENDOR-BEGIN(fence-mask)` region — `_FENCE_RE`, `fenced_flags`, `mask_fenced`
#            upstream source   plugins/coord/_vendor/fence-mask.py.txt
#            spliced into      plugins/coord/hooks/bin/_synclib.py (and `templates/bin/
#                              state-retention.py`, which is the same island case as this file)
#            fragment-sha256   d436f47da573c297 — carried on the GENERATED stamp line below,
#                              WRITTEN BY UPSTREAM'S OWN GENERATOR, and it is the first 16 hex
#                              characters of `sha256` over the spliced bytes. So this one piece
#                              is SELF-VERIFYING with no plugin and no network:
#                              `bin/pr-body-fields.selftest.py` § fragment recomputes it and reds
#                              on a mismatch. That is the strongest cross-boundary check
#                              available here, and it is available only because upstream
#                              PUBLISHES the digest.
#            why vendorable    this region is a `_vendor/` fragment BY UPSTREAM'S DESIGN: the
#                              framework splices it into hosts that cannot import `hooks/bin/`.
#
#   piece 2  `_audit_field` — `review-prep.py`'s ONE grammar for the audit-row family
#            upstream source   plugins/coord/hooks/bin/review-prep.py
#            source sha256     2617a2e4340c9dccf76f208fde0a0bf8157df97265e4291b20fca1ff5b527174
#                              (the WHOLE upstream file; the function is extracted from it)
#            local edits       NONE — the function is byte-for-byte, docstring included.
#            ⚠ NOT A SANCTIONED `_vendor/` FRAGMENT. Unlike piece 1, upstream publishes no
#            fragment and no digest for this function, so nothing here can verify it offline;
#            the only check that can is the plugin-present parity arm named below. Asking
#            upstream to publish it as a `_vendor/` fragment is the fix for that gap and belongs
#            upstream, not here.
#
# WHY COPIES AND NOT AN IMPORT. `review-prep.py` is 1400+ lines and imports eight sibling modules
# from the plugin's `hooks/bin/` island; `_fence.outside_fences` delegates to `_synclib`, which is
# another 1700 lines with three further sibling imports. None of that exists on a hosted CI
# runner, the upstream repository is PRIVATE so a public runner cannot clone it, and vendoring is
# the framework's own documented answer for a host on the far side of an import boundary.
# `bin/vendor-pin-check.sh`'s header records the same finding for `bin/promote-cards-by-token`.
#
# WHAT THE PIN PROVES AND WHAT IT DOES NOT. `bin/vendor-pin-check.sh` pins the BODY below by
# sha256 and runs in the same workflow as the guard, so a LOCAL edit reds before the guard's
# verdict is trusted. A green pin means "this is what mezzanine last DECLARED", never "this
# matches upstream today". Two legs reach further: the fragment digest above, recomputed offline by
# `bin/pr-body-fields.selftest.py` § 5 (piece 1 only), and that file's § 6, which on a machine
# where the plugin resolves diffs BOTH pieces against the live modules and on a machine where it
# does not prints NOT VERIFIED HERE by name rather than passing quietly.
#
# ⛔ THIS FAMILY HAS NO LINE WINDOW, AND THE ABSENCE OF ONE IS WHY THE REAL FUNCTION IS HERE RATHER
# THAN A PATTERN. `_audit_field` is line-anchored over the WHOLE body, accepts both the bold and
# the plain spelling, and reads OUTSIDE the body's fences. `PupFuzz/mezzanine#185` carries `Built:`
# at line 44 and this function reads it correctly today, so a rule of the shape "it must be near
# the top" would red bodies that are CORRECT. `bin/pr-body-fields.py` asks this function for
# exactly that reason, and `bin/pr-body-fields.selftest.py` § 3 runs a windowed pattern over the
# line-44 body and requires it to RED — the wrong implementation executed, not merely described.
#
# RE-VENDORING. Replace a piece with its upstream text between the labelled dividers, leaving the
# glue line alone, then follow the three steps `bin/vendor-pin-check.sh` prints on a red.
#
# ── END OF MEZZANINE'S HEADER ─────────────────────────────────────────────────────────────────
import re
import types

# ── UPSTREAM `_vendor/fence-mask.py.txt`, BYTE-FOR-BYTE — see header piece 1 ──────────────────
# VENDOR-BEGIN(fence-mask)
# GENERATED — do not edit; source: plugins/coord/_vendor/fence-mask.py.txt; regenerate (framework repo only): python3 tools/gen-vendored.py; fragment-sha256: d436f47da573c297
_FENCE_RE = re.compile(r'^ {0,3}(?P<f>`{3,}|~{3,})(?P<info>.*)$')


def fenced_flags(text):
    """Per-line booleans over ``text.split("\\n")``: True when that line lies inside a fenced
    code block, the opening and closing fence lines INCLUDED (they are part of the example,
    not content).

    MARKDOWN STRUCTURE ONLY COUNTS OUTSIDE A FENCED CODE BLOCK. Inside a fence, a line that
    looks like a marker / a heading / a divider is an EXAMPLE OF one, quoted for a human — it
    is the thing being written ABOUT, not an instance of it. Every structural scanner that
    shipped without that distinction mis-parsed the same way, which is why this is ONE tracker
    every one of them consults rather than N local patches.

    THE GRAMMAR, stated here because this fragment is spliced whole into hosts that have no
    other description of it: CommonMark's fenced-code rules, minus what nothing here emits. An
    opening fence is >=3 backticks or tildes indented <=3 spaces (a backtick fence's info
    string may not itself contain a backtick); it closes on the first line with >= as many of
    the SAME character and nothing but whitespace after; an unclosed fence runs to end of
    document. Indented (4-space) code blocks are NOT tracked, and the PRECONDITION that makes
    that safe belongs to the caller, not to this: a scanner anchored at column 0 excludes an
    indented example BY ITS OWN ANCHOR. This sentence used to assert that as a fact about "the
    scanners that care", and a scanner that deliberately tolerates leading whitespace falsified
    it (`dispatch-grant.MARKERISH_RE`, which must still see a HAND-MANGLED marker and therefore
    cannot anchor at column 0). A caller that tolerates indentation reads an indented example as
    real and owns that remainder itself — as that one does, in writing, beside the pattern."""
    flags, fence_char, fence_len = [], None, 0
    for ln in text.split("\n"):
        m = _FENCE_RE.match(ln)
        if fence_char is None:
            opening = bool(m) and not (m.group("f")[0] == "`" and "`" in m.group("info"))
            if opening:
                fence_char, fence_len = m.group("f")[0], len(m.group("f"))
            flags.append(opening)
            continue
        flags.append(True)
        if (m and m.group("f")[0] == fence_char and len(m.group("f")) >= fence_len
                and not m.group("info").strip()):
            fence_char, fence_len = None, 0
    return flags


def mask_fenced(text):
    """`text` with every fenced-code line blanked to SPACES. SAME LENGTH, same newline
    positions, so an offset found in the mask indexes the ORIGINAL unchanged — which is what
    lets a caller splice on it.

    THE MASK DOCTRINE, BOTH CONJUNCTS — THIS DOCSTRING IS ITS SINGLE OWNER, and a caller
    arguing a pattern safe argues it HERE, against both. Blanking to spaces withholds a match
    ONLY for a pattern that (i) cannot span a line boundary AND (ii) cannot match a run of
    spaces or an all-space line. A pattern failing (ii) can GAIN matches on masked text: an
    all-space line is a line the mask MANUFACTURED, so ``^\\s*$`` and ``^ {3}$`` both match more
    often after masking, not less. Every wiring must be argued safe under BOTH clauses.

    THIS DOCSTRING USED TO STATE ONLY THE WITHHELD HALF — "the only thing the mask can do is
    withhold a match that a fenced example would otherwise have won". That was measurably
    false, not merely loose, and the gained direction was executed against this very function
    before the sentence was replaced. The withheld half is still true and still the point; it
    was never the whole statement."""
    return "\n".join(" " * len(ln) if f else ln
                     for ln, f in zip(text.split("\n"), fenced_flags(text)))
# VENDOR-END(fence-mask)

# ── MEZZANINE'S GLUE — THE ONLY NON-UPSTREAM CODE IN THIS FILE ────────────────────────────────
# Upstream `_audit_field` calls `_fence.outside_fences(...)`, where `_fence` is a SIBLING MODULE
# of `review-prep.py` that delegates to `_synclib.mask_fenced`. Neither module is reachable here,
# so this binds that one name to the `mask_fenced` spliced above. It is written as a stand-in
# OBJECT rather than as an edit to the function for one reason: it keeps `_audit_field` below
# BYTE-FOR-BYTE upstream, so re-vendoring it is a copy and drift is a `diff` that is either empty
# or the whole answer. An edited copy would make every future comparison a judgement call.
_fence = types.SimpleNamespace(outside_fences=mask_fenced)


# ── UPSTREAM `review-prep.py::_audit_field`, BYTE-FOR-BYTE — see header piece 2 ────────────────
def _audit_field(body, name):
    r"""The VALUE of the audit-trail line `name` in `body`, or `None` when the body carries none.

    ⛔ ONE GRAMMAR FOR THE WHOLE AUDIT-ROW FAMILY — THIS IS A PRIMITIVE BECAUSE TWO OF THEM WERE
    A DEFECT (canon #5). `Built:` and `**Coordinated in:**` are one family: same section, same
    contract, read by the same reviewer. They were matched by two hand-written regexes, strict in
    OPPOSITE directions — one demanded the `**` wrapper, the other refused it — so each rejected
    the spelling the other required, and a compliant PR was reported ABSENT. A verdict of ABSENT
    against a line that is RIGHT THERE is worse than no check: `agents/impl-reviewer.md § INPUT`
    tells the reviewer to CITE these lines rather than re-derive them, so the false accusation is
    what gets carried into the round. A third field added later must call this and not a third
    regex.

    THE ACCEPTED SPELLINGS ARE MEASURED, NOT ASSUMED. Over this repo's last 100 PR bodies
    (`gh api pulls?per_page=100`, counted 2026-09-07): `**Built:**` on 27 lines and plain `Built:`
    on 46; `**Coordinated in:**` on 55 and plain `Coordinated in:` on 9. BOTH spellings are live
    for BOTH fields, and both are documented in this tree — `docs/built-line.md` and
    `agents/impl-reviewer.md` write the bold form, `skills/release-pr/SKILL.md`'s Step-10
    checklist writes the plain one. The colon-OUTSIDE spelling `**Built**:` appears 0 times in
    that sample and is deliberately NOT accepted: tolerating an unmeasured form widens the guard
    for nobody (canon #3).

    What is NOT relaxed is the rest of the shape — line-anchored, at the start of the line, with a
    non-blank value. A `Built:` inside prose, or a bold header with nothing after it, is still
    not an audit-trail line, and for `Built:` the VALUE is then checked against the canonical set
    by the caller.

    ⛔ AND IT IS READ OUTSIDE THE BODY'S FENCES (`_fence.py`), WHICH WAS THE DEFECT (card#9047
    r2). A PR body that DOCUMENTS this grammar in a fenced example — ordinary in this repo, whose
    PRs are largely about the lines this function reads — had its EXAMPLE read as its FIELD:
    driven, a body whose fence contained `Built: dispatched (coder x9 / mechanic x9)` and
    `Coordinated in: evil/repo#1` returned exactly those, while its REAL lines sat below the
    fence. That is a plane-1 verdict computed off text the author quoted rather than claimed, on
    the surface `agents/impl-reviewer.md § INPUT` tells the reviewer to CITE instead of
    re-deriving — and `coord-review` then routes `--thread` off the same example's ref. The rule
    is the record's own (`coord-review.py`'s machine-line note): a fenced line is QUOTED PAYLOAD,
    never the document's own answer, and it is one module so the two cannot drift.

    The fail-safe direction is deliberate: a body whose ONLY `Built:` line is inside a fence now
    reads as ABSENT, which is a finding the author fixes by unfencing their own line, rather than
    a PASS computed from somebody else's text.
    """
    # ⛔ `[ \t]*`, NEVER `\s*`, BETWEEN THE FIELD NAME AND ITS VALUE. `\s` matches `\n`, so the
    # shipped pattern matched ACROSS a newline (driven: `"Built:\ndispatched (…)"` matched, taking
    # the NEXT line as the value). Read over the fence mask that makes a quoted example payload,
    # that spanning arm would take the first non-blank line AFTER a fenced block as the field's
    # value — the fence rule manufacturing the machine line it exists to suppress. `_fence.py`'s
    # header owns the mask doctrine and the measurement over all five reads.
    pattern = r"^(?:\*\*%(n)s:\*\*|%(n)s:)[ \t]*(?P<v>\S.*)$" % {"n": re.escape(name)}
    match = re.search(pattern, _fence.outside_fences(body or ""), re.MULTILINE)
    return match.group("v").strip() if match else None

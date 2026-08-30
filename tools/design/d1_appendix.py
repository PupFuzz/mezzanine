"""d1_appendix.py — the ONE parser for D1's captured-harness-payload appendix (§ 17).

WHY THIS IS A LIBRARY AND NOT A SECOND COPY. Two things in this repo need the same
population — "every hook payload D1's appendix publishes, and whether each is a real
capture or a DOCS-CITED stub":

  * `tools/design/verify-harness-facts.py` reads it to assert every fixture key against
    the installed harness binary, and to re-derive § 17's own reproduced-capture counts;
  * `bin/harness-fixture-drift.py` reads it to DERIVE `fleet-reporter/fixtures/hooks/`
    and refuse a fixture set that has drifted from it.

Written twice, those two parsers are free to disagree about what § 17 contains, and the
first thing a disagreement produces is one gate reporting clean over a population the
other one can see. That is the same restatement-with-no-guard defect the drift guard
exists to close, one level up, so § 17's grammar is spelled once — here.

WHAT IT REFUSES TO GUESS. Every structural fact is READ from the document and every
failure to read one RAISES (`AppendixError`) rather than returning a smaller population:

  * the appendix is located by its own heading (`## <N>. Appendix …`), and its number is
    DERIVED, never typed here — a renumber must not silently move the section this reads;
  * the DOCS-CITED stub subsection is located by its heading text, because a payload's
    `_source` is decided by which side of that heading it sits on and nothing else;
  * every ```json block inside the appendix must parse AND carry `hook_event_name`;
  * a payload block ANYWHERE ELSE in the document raises — otherwise the appendix stops
    being the whole population and both callers narrow silently;
  * an empty region raises. An empty result is a measurement that never happened.

ORDER IS PART OF THE FACT. Payloads are returned in document order, and the fixture
derivation preserves it, so "the same shapes in a different order" is drift rather than a
cosmetic difference nobody can see.
"""
from __future__ import annotations

import json
import re
from dataclasses import dataclass

# The two `_source` markers D1 § 17 / § 17.1 define for a vendored fixture. A capture is a
# measurement; a stub is the installed build's own declared key set with placeholder values.
# Confusing the two is a false MEASURED, which is D1's own headline defect class.
SOURCE_CAPTURE = "capture"
SOURCE_STUB = "docs-cited-stub"

# The appendix heading. The NUMBER is a capture group, not a literal: D1 is renumbered by
# hand and a hard-coded `## 17.` would either miss the section (loud, but for the wrong
# reason) or, worse, match a different one.
_APPENDIX_RE = re.compile(r"^##\s+(\d+)\.\s+Appendix\b")
# The stub subsection, located by the term § 17.1's heading is built around.
_STUB_HEADING_RE = re.compile(r"^###\s+\d+\.\d+\s+.*DOCS-CITED stub", re.I)
_HEADING_RE = re.compile(r"^(#{1,6})\s+\S")
_JSON_FENCE_RE = re.compile(r"^```json\n(.*?)\n```$", re.S | re.M)
_FENCE_LINE_RE = re.compile(r"^```")


class AppendixError(Exception):
    """The appendix could not be read. Never raised for a payload that merely differs."""


@dataclass(frozen=True)
class Payload:
    """One JSON block D1's appendix publishes, with where it came from."""

    hook: str
    obj: dict
    line: int      # 1-based line of the opening ```json fence, for a caller's report
    source: str    # SOURCE_CAPTURE | SOURCE_STUB — decided by region, never by the fixture


@dataclass(frozen=True)
class Appendix:
    """D1's captured-payload appendix, as read — the population AND where it was read from.

    The two region texts are returned rather than left to each caller to re-find: a caller
    that wants to assert something about § 17.1's prose would otherwise re-locate the
    subsection with its own literal (`text.find("### 17.1")`), which is a second anchor for
    the same fact and the one that goes stale on a renumber.
    """

    number: str            # the appendix's section number, as the document writes it
    payloads: list[Payload]
    section: str           # the appendix's full text
    stub_section: str      # the DOCS-CITED stub subsection's text

    @property
    def captures(self) -> list[Payload]:
        return [p for p in self.payloads if p.source == SOURCE_CAPTURE]

    @property
    def stubs(self) -> list[Payload]:
        return [p for p in self.payloads if p.source == SOURCE_STUB]


def _line_of(text: str, offset: int) -> int:
    return text.count("\n", 0, offset) + 1


def _headings(text: str) -> list[tuple[int, int, str]]:
    """Every markdown heading OUTSIDE a fenced code block, as (offset, level, line).

    Fence-aware on purpose. A `# comment` line inside a ``` block is not a heading, and a
    boundary scan that thinks it is would end a section early — silently shrinking the
    region this parser reads payloads from, which is the one failure mode a drift guard
    cannot afford to have in its own populator.
    """
    out, off, in_fence = [], 0, False
    for line in text.split("\n"):
        if _FENCE_LINE_RE.match(line):
            in_fence = not in_fence
        elif not in_fence:
            m = _HEADING_RE.match(line)
            if m:
                out.append((off, len(m.group(1)), line))
        off += len(line) + 1
    return out


def _blocks(text: str, lo: int, hi: int) -> list[tuple[int, str]]:
    """Every ```json fence in [lo, hi), as (offset-of-the-opening-fence, content)."""
    return [(m.start(), m.group(1)) for m in _JSON_FENCE_RE.finditer(text, lo, hi)]


def _payloads_in(text: str, lo: int, hi: int, source: str, where: str) -> list[Payload]:
    out: list[Payload] = []
    for off, body in _blocks(text, lo, hi):
        line = _line_of(text, off)
        try:
            obj = json.loads(body)
        except ValueError as e:
            raise AppendixError(
                f"L{line}: the JSON block in {where} does not parse ({e}) — this appendix is "
                f"the vendored fixtures' only authority, and a block that cannot be read is "
                f"a payload that would silently leave the population"
            ) from e
        if not isinstance(obj, dict) or "hook_event_name" not in obj:
            raise AppendixError(
                f"L{line}: the JSON block in {where} carries no `hook_event_name`, so nothing "
                f"here can say which hook it belongs to. Every block in the appendix is a hook "
                f"payload; move a non-payload example out of it, or teach this parser about it "
                f"deliberately — skipping it would drop it from every gate that reads this."
            )
        hook = obj["hook_event_name"]
        if not isinstance(hook, str) or not hook:
            raise AppendixError(f"L{line}: `hook_event_name` is not a non-empty string: {hook!r}")
        out.append(Payload(hook=hook, obj=obj, line=line, source=source))
    return out


def parse_appendix(doc_text: str) -> Appendix:
    """D1's captured-payload appendix: every payload, in document order, classified by region.

    Raises `AppendixError` — never returns a partial population — when any structural
    anchor is missing, ambiguous, or would leave a payload unclassified.
    """
    headings = _headings(doc_text)
    heads = [h for h in headings if _APPENDIX_RE.match(h[2])]
    if len(heads) != 1:
        raise AppendixError(
            f"expected exactly one `## <N>. Appendix …` heading in the document, found "
            f"{len(heads)} — the captured-payload appendix is this parser's only anchor. "
            f"Do NOT hard-code a section number here; fix the heading, or teach the parser "
            f"which appendix is the payload one."
        )
    ap_start = heads[0][0]
    ap_end = next((o for o, lvl, _ in headings if o > ap_start and lvl <= 2), len(doc_text))

    stubs = [h for h in headings
             if ap_start < h[0] < ap_end and _STUB_HEADING_RE.match(h[2])]
    if len(stubs) != 1:
        raise AppendixError(
            f"expected exactly one `### <N>.<M> … DOCS-CITED stub …` heading inside the "
            f"appendix, found {len(stubs)}. A payload's `_source` is decided by which side of "
            f"that heading it sits on and by nothing else, so this parser will not guess. "
            f"⚠ If the LAST stub was closed and the subsection was deleted, that is a real "
            f"event and a deliberate edit here — letting it pass would reclassify four "
            f"DOCS-CITED stubs as captures, which is a false MEASURED."
        )
    stub_start = stubs[0][0]
    stub_end = next((o for o, _, _ in headings if o > stub_start and o < ap_end), ap_end)

    captures = _payloads_in(doc_text, ap_start, stub_start, SOURCE_CAPTURE,
                            "the appendix's capture body")
    stub_payloads = _payloads_in(doc_text, stub_start, stub_end, SOURCE_STUB,
                                 "the appendix's DOCS-CITED stub subsection")

    # NO SILENT REGION. The appendix's tail (§ 17.2 and anything after it) is classified by
    # neither heading, so a payload parked there belongs to no `_source` — report it rather
    # than letting it drop out of the population.
    tail = _blocks(doc_text, stub_end, ap_end)
    if tail:
        raise AppendixError(
            f"L{_line_of(doc_text, tail[0][0])}: a JSON block sits in the appendix AFTER the "
            f"DOCS-CITED stub subsection, where nothing classifies it as a capture or a stub. "
            f"Move it into the capture body or the stub subsection."
        )

    # …and the appendix must be the WHOLE population. A payload block elsewhere in the
    # document is invisible to every gate that reads this, which is a narrowing nobody would
    # see: it looks exactly like a document that never had that payload.
    for off, body in _blocks(doc_text, 0, len(doc_text)):
        if ap_start <= off < ap_end:
            continue
        try:
            obj = json.loads(body)
        except ValueError:
            continue          # a non-payload example that does not parse is D1's structural
        if isinstance(obj, dict) and "hook_event_name" in obj:   # gate's business, not ours
            raise AppendixError(
                f"L{_line_of(doc_text, off)}: a hook payload block sits OUTSIDE the appendix "
                f"(hook {obj['hook_event_name']!r}). The appendix is the vendored fixtures' "
                f"single authority; a payload published anywhere else reaches no fixture and "
                f"no gate. Move it into the appendix."
            )

    if not captures:
        raise AppendixError(
            "the appendix publishes NO captured payloads — an empty population is a "
            "measurement that never happened, not a clean result")
    if not stub_payloads:
        raise AppendixError(
            "the DOCS-CITED stub subsection publishes NO payloads — either the heading no "
            "longer bounds them or the stubs moved; either way the classification is not "
            "being read from where this parser thinks it is")

    payloads = captures + stub_payloads
    by_source: dict[str, set[str]] = {}
    for p in payloads:
        by_source.setdefault(p.hook, set()).add(p.source)
    both = sorted(h for h, s in by_source.items() if len(s) > 1)
    if both:
        raise AppendixError(
            f"hook(s) {both} publish payloads on BOTH sides of the DOCS-CITED stub heading. "
            f"One vendored fixture file carries one `_source`, so this cannot be reduced to a "
            f"fixture without picking one and calling a stub a capture (or the reverse). "
            f"Decide it in the document.")
    return Appendix(number=_APPENDIX_RE.match(heads[0][2]).group(1),
                    payloads=payloads,
                    section=doc_text[ap_start:ap_end],
                    stub_section=doc_text[stub_start:stub_end])


def fixture_object(payloads: list[Payload]) -> dict:
    """The vendored fixture object for ONE hook's payloads, in document order."""
    sources = {p.source for p in payloads}
    if len(sources) != 1:                      # unreachable via parse_appendix, which rejects
        raise AppendixError(f"mixed `_source` for one hook: {sorted(sources)}")
    return {"_source": next(iter(sources)), "shapes": [p.obj for p in payloads]}


def fixture_text(obj: dict) -> str:
    """The exact bytes a vendored fixture file carries. THE serialization, not a comparison.

    `fleet-reporter/fixtures/hooks/*.json` are a mechanical reduction of the appendix, so
    this function IS their format: two-space indent, non-ASCII left as itself, one trailing
    newline. A fixture that differs only in whitespace is still regenerated, because a
    generated file with a second hand-maintained spelling is how the next divergence starts.
    """
    return json.dumps(obj, indent=2, ensure_ascii=False) + "\n"


def derive_fixtures(payloads: list[Payload]) -> dict[str, str]:
    """`<HookEventName>` -> the exact text of that hook's vendored fixture file."""
    grouped: dict[str, list[Payload]] = {}
    for p in payloads:
        grouped.setdefault(p.hook, []).append(p)
    return {hook: fixture_text(fixture_object(ps)) for hook, ps in grouped.items()}

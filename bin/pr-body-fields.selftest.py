#!/usr/bin/env python3
"""pr-body-fields.selftest.py — prove `bin/pr-body-fields.py` CAN red, red for the RIGHT reason,
and pass a body that is correct. A body-shape check nobody has seen fail is a decoration, and this
repository has an explicit problem with decorative green (card#9754, one level up).

IT RUNS IN CI, BEFORE THE GATE'S OWN VERDICT, AGAINST THIS CHECKOUT. A gate's verdict is worth
something only if the fixtures showing it can fail ran against the same bytes that are about to
judge somebody's PR.

WHAT EACH SECTION IS FOR — the sections are not a list of cases, they are the ways this gate could
be WRONG:
  § 1  CONTROL — a correct body PASSES. Without it every red below is satisfied by a check that
       always fails, which is the cheapest way to fake this file.
  § 2  the measured absence: the shape `PupFuzz/mezzanine#180` actually has.
  § 3  ⭐ NO LINE WINDOW — `Built:` at line 44 and at line 200 must PASS, and the META-CONTROL
       under it EXECUTES the wrong implementation: a windowed grep is run over the same line-44
       body and REQUIRED to red it. `PupFuzz/mezzanine#185` is that body and `_audit_field` reads
       it correctly today, so "it must be near the top" would red a CORRECT PR. Without the
       meta-control this section shows the gate behaves without showing that the obvious wrong
       gate would behave DIFFERENTLY, which is the whole claim.
  § 4  the inherited grammar: both spellings accepted, the colon-OUTSIDE spelling refused, an
       empty value refused, a FENCED line refused, a mid-prose mention refused. These are
       upstream's rules, asserted here so a re-vendor that changes them is visible rather than
       silent.
  § 5  the VENDORED FRAGMENT's published digest, recomputed offline. Upstream's own generator
       stamps `fragment-sha256` over the spliced bytes, so this piece can be checked against a
       figure UPSTREAM published with no plugin and no network.
  § 6  PARITY with the live plugin, where a plugin exists. On a hosted runner none does, and the
       correct output is then to name what was NOT verified — never to pass quietly.
  § 7  the CLI's exit codes end to end, because every caller of this gate reads rc, not prose.

⚠ THERE ARE NO ARMS FOR A `FROM:` LINE, AND THAT IS THE CURRENT RULE RATHER THAN AN OMISSION. The
gate neither requires nor rejects one (`bin/pr-body-fields.py`'s header owns why), so there is
nothing here to assert about it in either direction.

Exit 0 = every arm behaved. Exit 1 = at least one did not; the gate's verdict proves nothing.
"""
from __future__ import annotations

import hashlib
import importlib.util
import os
import pathlib
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)


def _load(path, name):
    """Import a hyphenated sibling BY PATH. `pr-body-fields.py` is not a legal module name, and
    re-implementing its table here to dodge that would make this file test a second copy of the
    thing it exists to check."""
    spec = importlib.util.spec_from_file_location(name, os.path.join(HERE, path))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


G = _load("pr-body-fields.py", "pr_body_fields")

FAILS = []


def check(ok, what, detail=""):
    print("%s %s%s" % ("ok  " if ok else "FAIL", what, (" — " + detail) if detail else ""))
    if not ok:
        FAILS.append(what)


def absent(body):
    """The names the real guard reports ABSENT, sorted."""
    return sorted(name for name, _r, v in G.judge(body) if v == G.FAIL)


FILLER = "some ordinary prose about the change."


def body(lines):
    """A body from a {line-number: text} map; every other line up to the max is filler."""
    top = max(lines)
    return "\n".join(lines.get(n, FILLER) for n in range(1, top + 1))


BUILT = "Built: dispatched (coder x1 / mechanic x0)"
COORD = "**Coordinated in:** card#9767"
GOOD = body({3: COORD, 5: BUILT})


def section(title):
    print("\n── %s" % title)


# ── § 1  CONTROL ──────────────────────────────────────────────────────────────────────────────
section("§ 1  CONTROL — a correct body passes")
check(absent(GOOD) == [], "a body carrying both fields PASSES", "no field reported absent")

# ── § 2  the measured absence ─────────────────────────────────────────────────────────────────
section("§ 2  the absence measured on this repo")
check(absent(body({3: COORD})) == ["Built"],
      "#180's shape (no Built: line at all) reds Built alone",
      "absent=%s" % absent(body({3: COORD})))
check(absent(body({3: BUILT})) == ["Coordinated in"],
      "a body with no Coordinated in: line reds that field alone")
check(absent("") == ["Built", "Coordinated in"], "an EMPTY body reds both")
check(absent("   \n\n  \n") == ["Built", "Coordinated in"], "a WHITESPACE-ONLY body reds both")

# ── § 3  no line window, and the wrong implementation executed ────────────────────────────────
section("§ 3  ⭐ NO LINE WINDOW — a windowed rule would red correct bodies")
built_at_44 = body({3: COORD, 44: BUILT})
check(absent(built_at_44) == [], "#185's shape — Built: at line 44 — PASSES")
deep = body({150: COORD, 200: "**Built:** dispatched (coder x1 / mechanic x0)"})
check(absent(deep) == [], "Built: at line 200 and Coordinated in: at line 150 PASS")

windowed = re.compile(r"(?m)^(?:\*\*Built:\*\*|Built:)[ \t]*\S")
head10 = "\n".join(built_at_44.split("\n")[:10])
check(not windowed.search(head10),
      "META-CONTROL: a windowed (first-ten-lines) rule REDS § 3's body",
      "which is a CORRECT body, so going through `_audit_field` rather than a windowed pattern "
      "is load-bearing and not a stylistic choice")
check(bool(windowed.search(built_at_44)),
      "and the same pattern finds the line over the WHOLE body",
      "so the meta-control above isolates the WINDOW, not a broken pattern")

# ── § 4  the inherited grammar ────────────────────────────────────────────────────────────────
section("§ 4  the audit-row grammar this repo INHERITS rather than writes")
for spelling in ("Built: dispatched (coder x1 / mechanic x0)",
                 "**Built:** dispatched (coder x1 / mechanic x0)"):
    check(absent(body({3: COORD, 5: spelling})) == [],
          "the %r spelling is accepted" % spelling.split(" ")[0])
for spelling in ("Coordinated in: card#9767", "**Coordinated in:** card#9767"):
    check(absent(body({3: spelling, 5: BUILT})) == [],
          "the %r spelling is accepted" % spelling.split("card")[0].strip())
check(absent(body({3: COORD, 5: "**Built**: dispatched (coder x1 / mechanic x0)"})) == ["Built"],
      "the colon-OUTSIDE spelling `**Built**:` is REFUSED — upstream measured it at 0 uses")
for empty in ("Built:", "**Built:**", "Built:   "):
    check(absent(body({3: COORD, 5: empty})) == ["Built"],
          "a Built: line with an EMPTY value reds (%r)" % empty)
check(absent(body({3: "**Coordinated in:**", 5: BUILT})) == ["Coordinated in"],
      "a Coordinated in: line with an EMPTY value reds")
fenced = "**Coordinated in:** card#9767\n\n```\nBuilt: dispatched (coder x9 / mechanic x9)\n```\n"
check(absent(fenced) == ["Built"],
      "a Built: line that exists ONLY inside a fence reds — a fenced line is quoted payload, "
      "never the document's own claim")
check(absent(body({3: COORD, 5: "the body says Built: dispatched somewhere mid-sentence"})) == ["Built"],
      "a Built: inside prose, not line-initial, reds")

# ── § 5  the vendored fragment's published digest ─────────────────────────────────────────────
section("§ 5  the fence-mask fragment against the digest UPSTREAM published for it")
src = pathlib.Path(HERE, "coord_audit_field.py").read_text()
m = re.search(r"# VENDOR-BEGIN\(fence-mask\)\n"
              r"# GENERATED[^\n]*?fragment-sha256: (?P<stamp>[0-9a-f]+)\n"
              r"(?P<frag>.*?)"
              r"# VENDOR-END\(fence-mask\)\n", src, re.S)
if m is None:
    check(False, "the VENDOR-BEGIN(fence-mask) region is present and well formed")
else:
    got = hashlib.sha256(m.group("frag").encode()).hexdigest()[: len(m.group("stamp"))]
    check(got == m.group("stamp"), "the spliced fragment matches its stamp",
          "stamp=%s computed=%s" % (m.group("stamp"), got))
    check(hashlib.sha256((m.group("frag") + "x").encode()).hexdigest()[:16] != m.group("stamp"),
          "and the digest DISCRIMINATES (a one-byte change does not match)")

# ── § 6  parity with the live plugin, where one exists ────────────────────────────────────────
section("§ 6  PARITY — the vendored body against the plugin this install runs")


def newest_plugin_root():
    base = pathlib.Path(os.environ.get("COORD_PLUGIN_ROOT") or "")
    if base.name and (base / "hooks/bin/review-prep.py").is_file():
        return base, "COORD_PLUGIN_ROOT"
    cache = pathlib.Path.home() / ".claude/plugins/cache/agent-board-framework/coord"
    if cache.is_dir():
        versions = sorted((p for p in cache.iterdir() if (p / "hooks/bin/review-prep.py").is_file()),
                          key=lambda p: [int(x) if x.isdigit() else x for x in p.name.split(".")])
        if versions:
            return versions[-1], "plugin cache v%s" % versions[-1].name
    return None, None


root, where = newest_plugin_root()
if root is None:
    print("◌ NOT VERIFIED HERE — no coord plugin resolves on this machine, so the vendored body "
          "could NOT be compared with upstream. This is the expected state on a hosted CI runner: "
          "the plugin is not installed and the upstream repository is private. The legs that DO "
          "run here are § 5's published digest and bin/vendor-pin-check.sh's body pin; neither can "
          "see upstream, and neither claims to.")
else:
    up_rp = (root / "hooks/bin/review-prep.py").read_text()
    up_af = re.search(r"def _audit_field\(body, name\):\n.*?\n    return match\.group\(\"v\"\)"
                      r"\.strip\(\) if match else None\n", up_rp, re.S)
    if up_af is None:
        check(False, "upstream `_audit_field` could still be located in review-prep.py",
              "extraction FAILED — it was renamed or reshaped; re-read before trusting the copy")
    else:
        check(up_af.group(0) in src,
              "bin/coord_audit_field.py carries upstream `_audit_field` verbatim (%s)" % where,
              "a red here means upstream MOVED — re-vendor, do not edit the copy")
    up_frag = (root / "_vendor/fence-mask.py.txt").read_text()
    check(up_frag in src,
          "and the fence-mask fragment matches upstream's source fragment (%s)" % where)

# ── § 7  the CLI's exit codes ─────────────────────────────────────────────────────────────────
section("§ 7  exit codes end to end — every caller reads rc, not prose")
GUARD = os.path.join(HERE, "pr-body-fields.py")


def run(body_text, args=None):
    argv = [sys.executable, GUARD] + (args if args is not None else ["--body-file=-"])
    p = subprocess.run(argv, input=body_text, capture_output=True, text=True)
    return p.returncode, p.stdout + p.stderr


rc, out = run(GOOD)
check(rc == 0, "a complete body exits 0", "rc=%d" % rc)
rc, out = run("")
check(rc == 1, "a body missing both fields exits 1", "rc=%d" % rc)
check("Built: IS ABSENT" in out and "Coordinated in: IS ABSENT" in out,
      "and names BOTH missing fields with their remedy")
rc, out = run(GOOD)
check("card#9767" not in out and "dispatched" not in out,
      "NO BODY TEXT reaches the output — presence only, so no value can be printed")
rc, out = run("", ["--body-file=/nonexistent/there-is-no-such-body"])
check(rc == 2, "an unreadable body exits 2, never a verdict", "rc=%d" % rc)
rc, out = run("", [])
check(rc == 2, "no arguments exits 2 with usage", "rc=%d" % rc)
rc, out = run(GOOD, ["--body-file=-", "--unknown"])
check(rc == 2, "an unknown option exits 2", "rc=%d" % rc)

# ── verdict ───────────────────────────────────────────────────────────────────────────────────
print("")
if FAILS:
    print("pr-body-fields.selftest: %d arm(s) FAILED — the gate's verdict proves nothing:"
          % len(FAILS))
    for f in FAILS:
        print("  - %s" % f)
    sys.exit(1)
print("pr-body-fields.selftest: every arm behaved.")

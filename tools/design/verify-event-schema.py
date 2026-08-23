#!/usr/bin/env python3
"""Verification gate for the D1 design doc: links, anchors, JSON, bans, finding ids."""
import json, re, sys, pathlib

ROOT = pathlib.Path(__file__).parent.parent.parent
DOC = ROOT / "docs/design/EVENT-SCHEMA.md"
fail = []

def anchors_of(path):
    """GitHub-flavoured heading anchors for a markdown file."""
    out = set()
    seen = {}
    for line in path.read_text().splitlines():
        m = re.match(r"^(#{1,6})\s+(.*?)\s*$", line)
        if not m:
            continue
        text = m.group(2)
        text = re.sub(r"`([^`]*)`", r"\1", text)          # code spans
        text = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", text)  # links
        text = re.sub(r"[*~]", "", text)                   # emphasis (NOT _: GitHub keeps it)
        a = text.lower()
        a = re.sub(r"[^\w\- ]", "", a)                     # drop punctuation
        a = a.replace(" ", "-")
        if a in seen:
            seen[a] += 1
            a = f"{a}-{seen[a]}"
        else:
            seen[a] = 0
        out.add(a)
    return out

def strip_code(s):
    """Blank out inline code spans and fenced blocks so regex literals are not read as links."""
    s = re.sub(r"```.*?```", lambda m: "\n" * m.group(0).count("\n"), s, flags=re.S)
    return re.sub(r"`[^`\n]*`", lambda m: " " * len(m.group(0)), s)

doc_anchors = anchors_of(DOC)
raw = DOC.read_text()
text = raw

# ---- 1. links -----------------------------------------------------------------
for m in re.finditer(r"\]\(([^)\s]+)\)", strip_code(text)):
    target = m.group(1)
    line = text[:m.start()].count("\n") + 1
    if target.startswith("#"):
        if target[1:] not in doc_anchors:
            fail.append(f"L{line}: dead in-doc anchor {target}")
    elif target.startswith("http"):
        continue
    else:
        path, _, frag = target.partition("#")
        fp = (DOC.parent / path).resolve()
        if not fp.exists():
            fail.append(f"L{line}: missing file {target}")
        elif frag and frag not in anchors_of(fp):
            fail.append(f"L{line}: dead anchor in {path}: #{frag}")

# links inside VERSIONING.md too (we edited it)
V = ROOT / "docs/VERSIONING.md"
vt = V.read_text()
v_anchors = anchors_of(V)
for m in re.finditer(r"\]\(([^)\s]+)\)", strip_code(vt)):
    target = m.group(1)
    line = vt[:m.start()].count("\n") + 1
    if target.startswith("#"):
        if target[1:] not in v_anchors:
            fail.append(f"VERSIONING L{line}: dead anchor {target}")
    elif not target.startswith("http"):
        path, _, frag = target.partition("#")
        fp = (V.parent / path).resolve()
        if not fp.exists():
            fail.append(f"VERSIONING L{line}: missing file {target}")
        elif frag and frag not in anchors_of(fp):
            fail.append(f"VERSIONING L{line}: dead anchor in {path}: #{frag}")

# ---- 2. json fences -----------------------------------------------------------
n_json = 0
for m in re.finditer(r"```json\n(.*?)```", text, re.S):
    body = m.group(1)
    line = text[:m.start()].count("\n") + 1
    if "…" in body or "..." in body:
        continue
    try:
        json.loads(body)
        n_json += 1
    except Exception as e:
        fail.append(f"L{line}: json parse error: {e}")

# ---- 3. banned adjectives without a number ------------------------------------
BAN = r"\b(short|frequent|appropriate|reasonable|a while|soon)\b"
for i, line in enumerate(text.splitlines(), 1):
    for m in re.finditer(BAN, line, re.I):
        if not re.search(r"\d", line):
            fail.append(f"L{i}: banned adjective {m.group(0)!r} with no number: {line.strip()[:100]}")

# ---- 4. finding ids must appear nowhere ---------------------------------------
for pat in [r"\bB-[1-6]\b", r"\bM-(?:[1-9]|1[0-2])\b", r"\bm-(?:[1-9]|10)\b", r"\bh-[1-4]\b"]:
    for m in re.finditer(pat, text):
        line = text[:m.start()].count("\n") + 1
        fail.append(f"L{line}: review finding id leaked: {m.group(0)}")

# ---- 5. TODO / TBD ------------------------------------------------------------
for i, line in enumerate(text.splitlines(), 1):
    if re.search(r"\b(TODO|TBD|FIXME|XXX)\b", line):
        fail.append(f"L{i}: placeholder marker: {line.strip()[:100]}")

print(f"json blocks parsed: {n_json}; doc anchors: {len(doc_anchors)}")
if fail:
    print(f"\nFAILURES ({len(fail)}):")
    for f in fail:
        print("  -", f)
    sys.exit(1)
print("ALL CHECKS PASS")

#!/usr/bin/env python3
"""Generate every coordination fixture from ONE committed spec, check the committed tree against a fresh
generation, and lint the test tree for the unmistakable signs of a captured real message.

`docs/design/COORD-STORE.md § 3.5` (card#12239) owns the rules this file implements; they are not
restated here beyond what a reader of this file needs.

  python3 tools/coord/synthesize-fixtures.py            # write server/tests/fixtures/coord/generated/
  python3 tools/coord/synthesize-fixtures.py --check    # regenerate into a temp dir and byte-compare
  python3 tools/coord/synthesize-fixtures.py --lint     # the narrow lint over the test/tool population

GENERATION IS THE GUARD. A fixture nobody can hand-write cannot carry pasted content: the only inputs
are the values the spec (`server/tests/fixtures/coord/spec.json`) enumerates, and every output is a pure
function of them — no randomness, no clock, no network. `--check` reds on any hand edit, any stale file
and any file the spec no longer names, in both directions.

THE LINT IS NARROW, BY RULING (the operator's answer of 2026-10-23, "do as you recommended"). Three
case-sensitive regular expressions — a delivery header name, a bare header line at the start of a
line, the same line inside a quoted or escaped string — over the files `population()` re-derives on
every run. Exemptions are `(path, rule)` pairs in `tools/coord/lint-exemptions.txt`, each with a reason;
a pair whose file no longer matches its rule reds, so the list only shrinks.

⛔ THIS FILE SPELLS NONE OF THE STRINGS IT LINTS FOR. The header names and the body-line templates the
generator writes are read from the spec, and the lint's own patterns are assembled from the spec's
header table and body-line templates, so this file needs no exemption of its own and the spec stays
the one place the vocabulary lives. What that cannot catch is named in COORD-STORE.md § 3.5.
"""

import argparse
import datetime as dt
import difflib
import filecmp
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SPEC = ROOT / "server" / "tests" / "fixtures" / "coord" / "spec.json"
GENERATED = ROOT / "server" / "tests" / "fixtures" / "coord" / "generated"
EXEMPTIONS = ROOT / "tools" / "coord" / "lint-exemptions.txt"

# The lint's population, as COORD-STORE.md § 3.5 item 5 states it — re-derived per run, never counted.
POP_DIRS = ("server/tests/", "tools/", "bin/", "fleet-reporter/")
POP_EXT = re.compile(r"\.(php|js|mjs|py|json|txt|md|sh)$")
SLUG = re.compile(r"^[a-z0-9-]{1,48}$")


class SpecError(Exception):
    pass


def load_spec(path: Path = SPEC) -> dict:
    return json.loads(path.read_text(encoding="utf-8"))


# ── the spec's enumerations, and the refusal of anything outside them ───────────────────────────────
def enumerated_names(spec: dict) -> set:
    n = spec["names"]
    return set(n["install"]) | set(n["roundtable"]) | {n["broadcast"], n["unhoused"]} | set(n.get("malformed", []))


def need_name(spec: dict, name, where: str):
    if name is None:
        return None
    if name not in enumerated_names(spec):
        raise SpecError(f"{where}: {name!r} is not a name the spec enumerates")
    return name


def iso(spec: dict, minutes: int = 0, seconds: int = 0) -> str:
    base = dt.datetime.strptime(spec["ids"]["time_base"], "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc)
    return (base + dt.timedelta(minutes=minutes, seconds=seconds)).strftime("%Y-%m-%dT%H:%M:%SZ")


def iso_ms(spec: dict, minutes: int = 0, seconds: int = 0) -> str:
    return iso(spec, minutes, seconds)[:-1] + ".000Z"


def body_text(spec: dict, d: dict) -> str:
    out = []
    for line in d.get("lines", []):
        key, *arg = line
        if key not in spec["body_lines"]:
            raise SpecError(f"{d['key']}: body-line key {key!r} is not one the spec enumerates")
        sentence = spec["sentences"][arg[0]] if arg else ""
        to = d.get("to") or []
        out.append(spec["body_lines"][key].format(**{"from": d.get("from") or "", "to": ", ".join(to), "sentence": sentence}))
    return "\n".join(out)


def title_text(spec: dict, d: dict) -> str:
    title = spec["titles"][d["title"]]
    if d.get("carrier") is None:
        return title
    return spec["title_form"].format(carrier=d["carrier"].upper(), **{"from": d.get("from") or "", "to": ", ".join(d.get("to") or []), "title": title})


def repository(spec: dict, which: str) -> dict:
    full = spec["repositories"][which]["full_name"]
    owner, name = full.split("/", 1)
    if owner != spec["owner"]:
        raise SpecError(f"repository {full!r} is not owned by the spec's owner")
    return {"id": spec["ids"]["repository_id"] + (0 if which == "install" else 1), "name": name, "full_name": full,
            "private": True, "owner": {"login": owner}, "html_url": f"https://{spec['host']}/{full}"}


def labels(spec: dict, d: dict) -> list:
    out = []
    for side in ("from", "to"):
        for name in d.get("labels", {}).get(side, []):
            need_name(spec, name, f"{d['key']} label")
            out.append({"name": spec["label_prefixes"][side] + name})
    return out


def issue(spec: dict, d: dict) -> dict:
    n = spec["ids"]["thread_base"] + d["thread"]
    repo = repository(spec, d["repo"])
    closed = d.get("action") == "closed"
    i = {
        "number": n,
        "title": title_text(spec, d),
        "body": body_text(spec, d),
        "state": "closed" if closed else "open",
        "state_reason": "completed" if closed else None,
        "closed_at": iso(spec, d["thread"], 59) if closed else None,
        "created_at": iso(spec, d["thread"]),
        "updated_at": iso(spec, d["thread"], 30),
        "labels": labels(spec, d),
        "user": {"login": spec["login"]},
        "html_url": f"{repo['html_url']}/issues/{n}",
    }
    if d.get("pull_request"):
        i["pull_request"] = {"html_url": f"{repo['html_url']}/pull/{n}"}
    return i


def delivery_payload(spec: dict, d: dict) -> dict:
    repo = repository(spec, d["repo"])
    if d["event"] == "ping":
        return {"zen": spec["sentences"][0], "hook_id": spec["ids"]["hook_id"],
                "hook": {"type": "Repository", "id": spec["ids"]["hook_id"], "events": ["issues", "issue_comment"]},
                "repository": repo, "sender": {"login": spec["login"]}}
    need_name(spec, d.get("from"), d["key"])
    for t in d.get("to") or []:
        need_name(spec, t, d["key"])
    if d.get("carrier") is not None and not SLUG.match(d["carrier"]):
        raise SpecError(f"{d['key']}: carrier {d['carrier']!r} is not a slug")
    if "title" not in d:
        d = dict(d, title=0, carrier=d.get("carrier"))
    payload = {"action": d["action"], "issue": issue(spec, d)}
    if d["event"] == "issue_comment":
        cid = spec["ids"]["comment_base"] + d["comment"]
        payload["comment"] = {"id": cid, "body": body_text(spec, d), "created_at": iso(spec, d["thread"], d["comment"]),
                              "updated_at": iso(spec, d["thread"], d["comment"]), "user": {"login": spec["login"]},
                              "issue_url": f"https://{spec['host']}/repos/{repo['full_name']}/issues/{payload['issue']['number']}"}
        payload["issue"]["body"] = body_text(spec, dict(d, lines=[["plain", 0]]))
    payload["repository"] = repo
    payload["sender"] = {"login": spec["login"]}
    return payload


def headers_for(spec: dict, d: dict, index: int) -> list:
    guid = spec["ids"]["delivery_guid_prefix"] + f"{index + 1:012d}"
    values = {"guid": guid, "event": d["event"], "hook_id": str(spec["ids"]["hook_id"]),
              "repository_id": str(repository(spec, d["repo"])["id"]), "target_type": spec["ids"]["installation_target_type"]}
    out = []
    for h in spec["headers"]:
        row = {"role": h["role"], "name": h["name"]}
        if "sign" in h:
            row["sign"] = h["sign"]
        else:
            row["value"] = h["value"].format(**values)
        out.append(row)
    return out


def round_envelope(spec: dict, r: dict) -> dict:
    which = "install" if r["scope"] == "install" else "fleet"
    full = spec["repositories"][which]["full_name"]
    if spec["repositories"][which]["install_id"] != r["install_id"]:
        raise SpecError(f"{r['key']}: install_id {r['install_id']!r} is not its repository's binding's")
    need_name(spec, r["from"], r["key"])
    for t in r["to"]:
        need_name(spec, t, r["key"])
    thread_ref = f"{full}#{spec['ids']['thread_base'] + r['thread']}"
    post_ref = thread_ref if "comment" not in r else f"{thread_ref}-c{spec['ids']['comment_base'] + r['comment']}"
    # D1 § 18.7's rule, never written by hand: `null` on a broadcast, else `to` less the author.
    if spec["names"]["broadcast"] in r["to"]:
        targets = None
    else:
        targets = []
        for t in r["to"]:
            if t != r["from"] and t not in targets:
                targets.append(t)
    at = iso_ms(spec, r["thread"], r.get("comment", 0) % 60)
    return {"feed_version": 1, "t": "coord.round", "server_time": at, "coord_round": {
        "post_ref": post_ref, "thread_ref": thread_ref, "scope": r["scope"], "install_id": r["install_id"],
        "from": r["from"], "attribution": "resolved", "to": list(r["to"]), "targets": targets, "carrier": None,
        "declares_close": bool(r.get("declares_close", False)), "posted_at": at, "received_at": at}}


def dump(obj) -> str:
    return json.dumps(obj, ensure_ascii=False, separators=(",", ":"))


def generate(spec: dict, out: Path) -> list:
    """Write every fixture into `out`; return the relative paths written, sorted."""
    written = []

    def put(rel: str, text: str):
        p = out / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(text, encoding="utf-8")
        written.append(rel)

    keys = [d["key"] for d in spec["deliveries"]] + [r["key"] for r in spec["rounds"]]
    if len(keys) != len(set(keys)):
        raise SpecError("a delivery or round key is used twice")
    for i, d in enumerate(spec["deliveries"]):
        # The BODY is the exact byte string a signature is computed over, so it is written as-is and
        # never pretty-printed; the headers file beside it says which header carries which signature.
        put(f"deliveries/{d['key']}.body.json", dump(delivery_payload(spec, d)))
        put(f"deliveries/{d['key']}.headers.json", json.dumps(headers_for(spec, d, i), indent=2) + "\n")
    for r in spec["rounds"]:
        put(f"rounds/{r['key']}.json", json.dumps(round_envelope(spec, r), indent=2, ensure_ascii=False) + "\n")
    return sorted(written)


def check(spec: dict) -> int:
    with tempfile.TemporaryDirectory() as tmp:
        fresh = Path(tmp)
        generate(spec, fresh)
        want = sorted(str(p.relative_to(fresh)) for p in fresh.rglob("*") if p.is_file())
        have = sorted(str(p.relative_to(GENERATED)) for p in GENERATED.rglob("*") if p.is_file()) if GENERATED.exists() else []
        problems = []
        for rel in sorted(set(want) - set(have)):
            problems.append(f"missing from the committed tree: {rel}")
        for rel in sorted(set(have) - set(want)):
            problems.append(f"in the committed tree and named by no spec entry: {rel}")
        for rel in sorted(set(want) & set(have)):
            if not filecmp.cmp(fresh / rel, GENERATED / rel, shallow=False):
                diff = list(difflib.unified_diff((GENERATED / rel).read_text(encoding="utf-8").splitlines(),
                                                 (fresh / rel).read_text(encoding="utf-8").splitlines(), lineterm="", n=0))[:6]
                problems.append(f"differs from a fresh generation: {rel}\n    " + "\n    ".join(diff))
    for p in problems:
        print(f"FAIL {p}")
    print(f"generated files compared: {len(want)}; problems: {len(problems)}")
    return 1 if problems else 0


# ── the narrow lint ──────────────────────────────────────────────────────────────────────────────────
def rules(spec: dict) -> dict:
    """The three pinned rules, assembled from the spec's own vocabulary (COORD-STORE.md § 3.5 item 5)."""
    # The delivery-id header and the shorter signature header's name, which the longer one extends.
    names = [h["name"] for h in spec["headers"] if h["role"] in ("delivery", "signature-1")]
    header = "|".join(sorted(re.escape(n) for n in names))
    tokens = sorted({spec["body_lines"][k].split(":", 1)[0] for k in ("from-line", "to-line")})
    alt = "|".join(tokens)
    return {
        "header": re.compile(header),
        "bare": re.compile(rf"^({alt}):\s", re.M),
        "quoted": re.compile(r"([\"']|\\n|\\r\\n)(" + alt + r"):\s"),
    }


def population(root: Path = ROOT) -> list:
    out = subprocess.run(["git", "-C", str(root), "ls-files", "--cached", "--others", "--exclude-standard"],
                         check=True, capture_output=True, text=True).stdout.split("\n")
    gen = str(GENERATED.relative_to(ROOT)) + "/"
    spec = str(SPEC.relative_to(ROOT))
    return sorted(f for f in out if f and f.startswith(POP_DIRS) and POP_EXT.search(f)
                  and not f.startswith(gen) and f != spec and (root / f).is_file())


def read_exemptions(path: Path = EXEMPTIONS) -> tuple:
    pairs, problems = {}, []
    for n, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        m = re.match(r"^(\S+)\s+(header|bare|quoted)\s+#\s*(\S.*)$", line)
        if not m:
            problems.append(f"{path.name}:{n}: not `<path> <rule> # <reason>`: {raw}")
            continue
        if (m.group(1), m.group(2)) in pairs:
            problems.append(f"{path.name}:{n}: duplicate pair {m.group(1)} {m.group(2)}")
        pairs[(m.group(1), m.group(2))] = m.group(3)
    return pairs, problems


def lint(spec: dict, root: Path = ROOT, exemptions: Path = EXEMPTIONS) -> int:
    rx = rules(spec)
    pairs, problems = read_exemptions(exemptions)
    files = population(root)
    hits = set()
    for f in files:
        text = (root / f).read_text(encoding="utf-8", errors="replace")
        for rule, pattern in rx.items():
            if pattern.search(text):
                hits.add((f, rule))
    for f, rule in sorted(hits - set(pairs)):
        problems.append(f"{f}: rule `{rule}` matches and no exemption names the pair — a captured real "
                        f"message's sign outside the generated directory (COORD-STORE.md § 3.5)")
    for f, rule in sorted(set(pairs) - hits):
        problems.append(f"{exemptions.name}: the pair `{f} {rule}` exempts a file that no longer matches "
                        f"that rule — the list only shrinks; remove it")
    for p in problems:
        print(f"FAIL {p}")
    print(f"lint population: {len(files)} files ({' '.join(POP_DIRS)}); hits: {len(hits)}; "
          f"exempted pairs: {len(pairs)}; problems: {len(problems)}")
    return 1 if problems else 0


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    g = ap.add_mutually_exclusive_group()
    g.add_argument("--check", action="store_true", help="regenerate into a temp dir and byte-compare")
    g.add_argument("--lint", action="store_true", help="the narrow lint over the test/tool population")
    a = ap.parse_args(argv)
    try:
        spec = load_spec()
        if a.check:
            return check(spec)
        if a.lint:
            return lint(spec)
        if GENERATED.exists():
            for p in sorted(GENERATED.rglob("*"), reverse=True):
                p.unlink() if p.is_file() else p.rmdir()
        written = generate(spec, GENERATED)
        print(f"wrote {len(written)} files under {GENERATED.relative_to(ROOT)}")
        return 0
    except SpecError as e:
        print(f"FAIL spec: {e}")
        return 1


if __name__ == "__main__":
    sys.exit(main())

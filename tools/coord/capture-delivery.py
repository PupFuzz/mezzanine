#!/usr/bin/env python3
"""Capture ONE real GitHub webhook delivery for a human editing the coordination spec's SHAPE — never its
values — under the two rules `docs/design/COORD-STORE.md § 3.5` item 6 sets (card#12239).

  gh api repos/<owner>/<repo>/hooks/<hook id>/deliveries/<delivery id> | python3 tools/coord/capture-delivery.py
  python3 tools/coord/capture-delivery.py --in delivery.json [--out <path>]

1. EVERY SIGNATURE HEADER IS STRIPPED BEFORE ANYTHING IS WRITTEN — any request header whose name
   carries `signature`, case-insensitively — so a capture cannot be replayed against any hook.
2. IT WRITES ONLY OUTSIDE EVERY GIT WORKTREE, under `${XDG_CACHE_HOME:-~/.cache}/coord/captures/` by
   default, at mode 0600, and FAILS CLOSED: it writes only when `git -C <dir> rev-parse
   --is-inside-work-tree` exits 128 with the not-a-repository message. Exit 0, any other error, or
   `git` missing refuses.

It prints the payload's KEY PATHS and the capture's size, and no value. Nothing copies a capture's
values anywhere: a capture informs a human editing `server/tests/fixtures/coord/spec.json`'s shape, and
the spec is the only place content enters the fixtures. The tool reads no network and holds no
credential; the operator pipes GitHub's own delivery record into it.
"""

import argparse
import json
import os
import subprocess
import sys
import time
from pathlib import Path


def outside_any_worktree(directory: Path) -> "str | None":
    """None when `directory` may be written; otherwise why not. Fail closed."""
    try:
        r = subprocess.run(["git", "-C", str(directory), "rev-parse", "--is-inside-work-tree"],
                           capture_output=True, text=True)
    except (OSError, FileNotFoundError):
        return "git could not be run, so whether the path is inside a worktree is unknown"
    if r.returncode == 128 and "not a git repository" in r.stderr:
        return None
    if r.returncode == 0:
        return f"{directory} is inside a git worktree, and a capture there is one `git add` from a commit"
    return f"git did not confirm {directory} is outside every worktree (exit {r.returncode})"


def strip_signatures(record: dict) -> int:
    """Remove every header whose name carries `signature` from the record's request; return how many."""
    removed = 0
    request = record.get("request") if isinstance(record.get("request"), dict) else record
    headers = request.get("headers")
    if isinstance(headers, dict):
        for name in [n for n in headers if "signature" in n.lower()]:
            del headers[name]
            removed += 1
    return removed


def key_paths(value, prefix="") -> list:
    if isinstance(value, dict):
        out = []
        for k in sorted(value):
            out.extend(key_paths(value[k], f"{prefix}.{k}" if prefix else k))
        return out or [prefix + " {}"]
    if isinstance(value, list):
        return [f"{prefix}[] ({len(value)} items)"] + (key_paths(value[0], prefix + "[0]") if value else [])
    return [f"{prefix} <{type(value).__name__}>"]


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="capture one GitHub delivery outside every worktree, signatures stripped")
    ap.add_argument("--in", dest="src", default="-", help="the delivery record's JSON file, or - for stdin")
    ap.add_argument("--out", help="where to write; default ${XDG_CACHE_HOME:-~/.cache}/coord/captures/<time>.json")
    a = ap.parse_args(argv)

    raw = sys.stdin.read() if a.src == "-" else Path(a.src).read_text(encoding="utf-8")
    try:
        record = json.loads(raw)
    except json.JSONDecodeError as e:
        print(f"REFUSED: the input is not JSON ({e.msg})")
        return 1
    if not isinstance(record, dict):
        print("REFUSED: the input is not a JSON object")
        return 1

    cache = Path(os.environ.get("XDG_CACHE_HOME") or Path.home() / ".cache")
    out = Path(a.out) if a.out else cache / "coord" / "captures" / time.strftime("%Y%m%dT%H%M%SZ.json", time.gmtime())
    out.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    why = outside_any_worktree(out.parent)
    if why:
        print(f"REFUSED: {why}. Nothing was written.")
        return 1

    removed = strip_signatures(record)
    data = json.dumps(record, indent=2, ensure_ascii=False)
    fd = os.open(out, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write(data)
    os.chmod(out, 0o600)

    payload = record.get("request", {}).get("payload") if isinstance(record.get("request"), dict) else None
    print(f"wrote {out} ({len(data.encode('utf-8'))} B, mode 0600); signature headers stripped: {removed}")
    for line in key_paths(payload if payload is not None else record):
        print("  " + line)
    return 0


if __name__ == "__main__":
    sys.exit(main())

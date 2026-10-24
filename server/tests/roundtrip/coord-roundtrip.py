#!/usr/bin/env python3
"""The coordination receipt's round trip: the GENERATED deliveries, signed under a real binding's
secret, posted over HTTP to a running Mezzanine (card#12239; `docs/design/COORD-STORE.md § 9` step 9 and
the sandbox acceptance's step 3).

  python3 server/tests/roundtrip/coord-roundtrip.py --base https://<host> --hook mzh_… \\
      --secret-file /path/outside/any/worktree/secret --repo install|fleet

The operator runs it, with the files `mezzanine:coord-hook:issue --secret-out` wrote. It reads the
secret from the file and never prints it; it posts every generated delivery whose repository is the
named binding's own (a body naming the other binding's repository is D1 § 18.8 step 6's `403`), and
prints each one's status and the receipt's `status` member. A second pass re-posts the first delivery
to show the digest absorbing it.

Every header NAME it sends is read from the spec's header table, and every body from the generated
tree — this file spells neither. Nothing here touches the store directly: `bin/feed-stream-check.sh`
and the store's own rows are how the operator reads what landed.
"""

import argparse
import hashlib
import hmac
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
COORD = HERE.parent / "fixtures" / "coord"


def post(base: str, hook: str, body: bytes, headers: dict) -> tuple:
    req = urllib.request.Request(f"{base.rstrip('/')}/api/ingest/github/{hook}", data=body, method="POST", headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, json.loads(r.read() or b"{}")
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read() or b"{}")
        except json.JSONDecodeError:
            return e.code, {}


def signed_headers(key: str, body: bytes, secret: str) -> dict:
    rows = json.loads((COORD / "generated" / "deliveries" / f"{key}.headers.json").read_text())
    out = {}
    for row in rows:
        if "sign" in row:
            out[row["name"]] = row["sign"] + "=" + hmac.new(secret.encode(), body, getattr(hashlib, row["sign"])).hexdigest()
        else:
            out[row["name"]] = row["value"]
    return out


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("--base", required=True, help="the Mezzanine base URL")
    ap.add_argument("--hook", required=True, help="the binding's mzh_… id")
    ap.add_argument("--secret-file", required=True, help="the file coord-hook:issue --secret-out wrote")
    ap.add_argument("--repo", required=True, choices=["install", "fleet"], help="which of the spec's repositories the binding is for")
    a = ap.parse_args(argv)

    spec = json.loads((COORD / "spec.json").read_text())
    secret = Path(a.secret_file).read_text().strip()
    keys = [d["key"] for d in spec["deliveries"] if d["repo"] == a.repo]
    failed = 0
    for key in keys:
        body = (COORD / "generated" / "deliveries" / f"{key}.body.json").read_bytes()
        status, answer = post(a.base, a.hook, body, signed_headers(key, body, secret))
        ok = status == 202
        failed += 0 if ok else 1
        print(f"{'ok  ' if ok else 'FAIL'} {key}: {status} {answer.get('status', answer.get('error', ''))}")

    first = keys[0]
    body = (COORD / "generated" / "deliveries" / f"{first}.body.json").read_bytes()
    status, answer = post(a.base, a.hook, body, signed_headers(first, body, secret))
    absorbed = status == 202 and answer.get("status") in ("duplicate", "ignored")
    failed += 0 if absorbed else 1
    print(f"{'ok  ' if absorbed else 'FAIL'} {first} re-posted: {status} {answer.get('status', answer.get('error', ''))} "
          "(a derived delivery is absorbed by its digest; an ignored one is counted again)")

    print(f"\n{len(keys) + 1} posts, {failed} unexpected")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())

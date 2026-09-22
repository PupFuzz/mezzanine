#!/usr/bin/env python3
"""release-facts.py — print the facts a seat cutting a release of this repo needs, on demand (card#9684).

It PRINTS; it blocks nothing and commits nothing. Every line is one of two kinds: a value quoted
from the source it names, or `NOT VERIFIED` naming what was not read and why. Nothing is inferred.

  1. FROM THE TREE (no network). Every job under `.github/workflows/`, with its `pull_request`
     trigger quoted and each lane that trigger can skip marked NEVER REQUIRABLE: a required check
     that produces no run waits forever (`docs/VERSIONING.md § Branch model`, the pending trap).
     The host floors, as the readers that enforce them print them — this file holds no floor.
  2. LIVE (best effort, GET only). `rules/branches/<b>` for `dev` and `main`: every rule instance
     verbatim, the bypass actors of each ruleset they come from, then a per-lane `gates` column.
     A failed read, a non-200 or an empty `[]` is NOT VERIFIED and every cell in that column is
     UNKNOWN: an empty answer is a measurement that did not happen, never "nothing is required".
  3. WHAT THIS CANNOT VERIFY, always printed.

EXIT: 0 printed; 1 a job declares a key that makes its status context differ from its job id
(`name:`, a matrix `strategy:`, a reusable-workflow `uses:`) — the gates column joins on job id,
so that join is a CHECKED fact here rather than an assumption; 2 the tree could not be read.
A live read that fails never changes the exit status.

CREDENTIAL: `GH_TOKEN`, else `GITHUB_TOKEN`, else unauthenticated. Which one is named in the
output; the value never is. A value holding anything but printable ASCII without spaces (so any
whitespace or control character) is REFUSED: the variable is named, nothing live is requested,
and both branches print NOT VERIFIED. A failed live read is reported by the exception's TYPE name
only, never its text, which can carry the request's headers.

`--responses FILE` replaces the network with a JSON map of `"<path>": {"status": N, "body": <json>}`
(the selftest's hermetic transport).
"""
from __future__ import annotations

import argparse
import http.client
import json
import os
import re
import shutil
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

API = "https://api.github.com"
BRANCHES = ("dev", "main")
PAGE = 100  # the API's per_page maximum; a full page is reported as possibly truncated
PR_EVENTS = ("pull_request", "pull_request_target")
PR_FILTERS = ("paths", "paths-ignore", "branches", "branches-ignore")
CONTEXT_KEYS = ("name", "strategy", "uses")  # each makes the check-run name differ from the job id
KEY = re.compile(r"^( *)([A-Za-z0-9_-]+|'[^']*'|\"[^\"]*\"):(?:\s+(.*))?$")


class TreeError(Exception):
    pass


def _rows(text: str):
    """(indent, key, inline value or '') for every mapping-key line; comments and blanks dropped."""
    out = []
    for n, line in enumerate(text.splitlines(), 1):
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        if "\t" in line[: len(line) - len(line.lstrip())]:
            raise TreeError(f"line {n}: tab indentation")
        m = KEY.match(line)
        ind = len(line) - len(line.lstrip(" "))
        val = re.sub(r"\s+#.*$", "", m.group(3) or "").strip() if m else ""
        out.append((ind, m.group(2).strip("'\"") if m else None, val, n))
    return out


def _children(rows, i):
    """Rows nested under rows[i], and the indent of its direct children."""
    body = []
    for r in rows[i + 1:]:
        if r[0] <= rows[i][0]:
            break
        body.append(r)
    return body, (body[0][0] if body else None)


def parse_workflow(text: str):
    """-> (pr_trigger, jobs). pr_trigger: None | {event: [filter keys]}; jobs: [(id, [context keys])]."""
    rows = _rows(text)
    top = {r[1]: i for i, r in enumerate(rows) if r[0] == 0 and r[1]}
    if "jobs" not in top:
        raise TreeError("no top-level jobs:")
    trig = None
    if "on" in top:
        i = top["on"]
        val = rows[i][2]
        if val.startswith("{"):
            raise TreeError(f"line {rows[i][3]}: flow-mapping on: is not read by this tool")
        if val:
            events = [e.strip() for e in val.strip("[]").split(",")]
            trig = {e: [] for e in events if e in PR_EVENTS} or None
        else:
            body, ci = _children(rows, i)
            for j, r in enumerate(body):
                if r[0] == ci and r[1] in PR_EVENTS:
                    if r[2].startswith("{"):  # its filters would be unread, and it would pass as unfiltered
                        raise TreeError(f"line {r[3]}: flow-mapping {r[1]}: is not read by this tool")
                    sub, fi = _children(body, j)
                    trig = trig or {}
                    trig[r[1]] = [s[1] for s in sub if s[0] == fi and s[1] in PR_FILTERS]
    body, ci = _children(rows, top["jobs"])
    jobs = []
    for j, r in enumerate(body):
        if r[0] == ci:
            if not r[1]:
                raise TreeError(f"line {r[3]}: not a job id")
            sub, ki = _children(body, j)
            jobs.append((r[1], [s[1] for s in sub if s[0] == ki and s[1] in CONTEXT_KEYS]))
    return trig, jobs


def tree_section(root: Path):
    wf = root / ".github" / "workflows"
    files = sorted(list(wf.glob("*.yml")) + list(wf.glob("*.yaml")))
    if not files:
        raise TreeError(f"no workflow files under {wf}")
    print("== 1. FROM THE TREE (no network) ==")
    print(f"workflow jobs under {wf.relative_to(root)}/ — requirability read from the pull_request trigger:")
    lanes, broken = {}, []
    for f in files:
        try:
            trig, jobs = parse_workflow(f.read_text(encoding="utf-8"))
        except TreeError as e:
            raise TreeError(f"{f.name}: {e}") from None
        if trig is None:
            how = "no pull_request trigger — NEVER REQUIRABLE (no run on a PR)"
        elif any(trig.values()):
            keys = sorted({k for v in trig.values() for k in v})
            how = f"pull_request filtered by {', '.join(keys)}: — NEVER REQUIRABLE (a PR outside the filter gets no run)"
        else:
            how = "pull_request: unfiltered"
        for job, keys in jobs:
            lanes[job] = trig is not None and not any(trig.values())
            print(f"  {job:<26} {f.name:<28} {how}")
            broken += [f"{f.name}: job `{job}` declares `{k}:`" for k in keys]
    if broken:
        for b in broken:
            print(f"  ✗ CONTEXT != JOB ID — {b}; its status context is not its job id")
    else:
        print("  ✓ no job declares name:/strategy:/uses:, so each status context is its job id (checked, not assumed)")
    floors(root, lanes)
    return lanes, broken


def _run(root: Path, argv, **kw):
    try:
        p = subprocess.run(argv, cwd=root, capture_output=True, text=True, timeout=60, **kw)
    except (OSError, subprocess.TimeoutExpired) as e:
        return None, f"{type(e).__name__}: {e}"
    return p.returncode, (p.stdout + p.stderr).strip()


def _quote(label: str, source: str, rc, out: str) -> None:
    if rc == 0 and out:
        print(f"  {label:<5} {out}   [{source}]")
    else:
        print(f"  {label:<5} NOT VERIFIED — {source} gave rc={rc}: {out!r}")


def floors(root: Path, lanes) -> None:
    print("host floors, as the readers that enforce them print them (this file holds no number):")
    src = ". ./bin/deploy.sh >/dev/null 2>&1; "
    rc, out = _run(root, ["bash", "-c", src + "bash_floor_declared < bin/deploy.sh"])
    how = ("measured at it and one minor below by job `bash-floor`" if "bash-floor" in lanes
           else "NO `bash-floor` job in this tree, so nothing measures it")
    _quote("bash:", f"bin/deploy.sh bash_floor_declared; {how}", rc, out)
    npm = shutil.which("npm")  # A12 compares a host npm against the release's lockfile: it needs one
    rc, out = _run(root, [npm, "--version"]) if npm else (None, "npm not on PATH")
    if rc == 0:
        rc, out = _run(root, ["bash", "-c", src + 'gate_a12_asset_lockfile HEAD "$RF_HOST_NPM"'],
                       env={**os.environ, "RF_HOST_NPM": out})  # not argv: sourcing resets "$@"
    _quote("npm:", "bin/deploy.sh A12 gate_a12_asset_lockfile at HEAD", rc, out)
    rc, out = _run(root, [sys.executable, "tools/verify-php-floor.py", "--root", str(root)])
    _quote("php:", "tools/verify-php-floor.py (reads the checkout)", rc, out)
    print("  git:  no declared number — probed on the host at deploy time by bin/deploy.sh A3b")


def credential():
    """-> (label, token, refused). The label names the variable, never the value. A GitHub token is
    printable ASCII with no spaces; anything else (a CR from a CRLF file, a pasted newline) is
    refused here rather than sent, because http.client quotes an illegal header value, token and
    all, in the text of the error it raises."""
    for var in ("GH_TOKEN", "GITHUB_TOKEN"):
        value = os.environ.get(var)
        if value:
            if not all("!" <= c <= "~" for c in value):
                return (f"{var} REFUSED — its value holds whitespace, a control character or non-ASCII,"
                        " so nothing live is requested"), None, True
            return var, value, None
    return "unauthenticated", None, None


def make_get(responses: Path | None, token: str | None):
    if responses:
        canned = json.loads(responses.read_text(encoding="utf-8"))

        def get(path):
            r = canned.get(path)
            return (r["status"], r.get("body")) if r else (None, "no canned response")
        return get

    def get(path):
        hdr = {"Accept": "application/vnd.github+json", "X-GitHub-Api-Version": "2022-11-28"}
        if token:
            hdr["Authorization"] = f"Bearer {token}"
        req = urllib.request.Request(f"{API}/{path}", method="GET", headers=hdr)
        try:
            with urllib.request.urlopen(req, timeout=20) as resp:
                return resp.status, json.load(resp)
        except urllib.error.HTTPError as e:
            return e.code, None
        except (OSError, ValueError, http.client.HTTPException) as e:  # every transport failure: NOT VERIFIED
            return None, _type_names(e)
    return get


def _type_names(e: BaseException) -> str:
    """TYPE NAMES ONLY, never str(e): an illegal header value is quoted, Authorization included, in
    the ValueError http.client raises, and a server's reply can come back inside a BadStatusLine.
    URLError wraps the socket or TLS error that caused it, so that type is named too."""
    reason = getattr(e, "reason", None)
    return type(e).__name__ + (f"({type(reason).__name__})" if isinstance(reason, BaseException) else "")


def live_section(repo: str, lanes, get, cred: str) -> tuple:
    print("\n== 2. LIVE (best effort, read-only GET) ==")
    print(f"credential: {cred}" + (" (value not printed)" if cred != "unauthenticated" else ""))
    required, ruleset_ids, hidden_bypass = {}, [], []
    for b in BRANCHES:
        path = f"repos/{repo}/rules/branches/{b}?per_page={PAGE}"
        status, body = get(path)
        if status != 200 or not isinstance(body, list) or not body or len(body) >= PAGE:
            why = ("empty [] (a 200 that says nothing — the branch may not exist)" if body == [] else
                   f"a full page of {PAGE}, so more may exist unread" if isinstance(body, list) and body else
                   f"status {status}" + (f", {body}" if isinstance(body, str) else ""))
            print(f"  {b}: NOT VERIFIED — GET {path} → {why}")
            required[b] = None
            continue
        print(f"  {b}: GET {path} → 200, {len(body)} rule instance(s), every one printed:")
        required[b] = set()
        for rule in body:
            print(f"    {rule.get('type')}  ruleset_id={rule.get('ruleset_id')} "
                  f"source={rule.get('ruleset_source_type')}:{rule.get('ruleset_source')}  "
                  f"parameters={json.dumps(rule.get('parameters'), sort_keys=True)}")
            if rule.get("type") == "required_status_checks":
                checks = (rule.get("parameters") or {}).get("required_status_checks") or []
                required[b] |= {c.get("context") for c in checks}
            if rule.get("ruleset_id") not in ruleset_ids:
                ruleset_ids.append(rule.get("ruleset_id"))
    for rid in ruleset_ids:
        status, body = get(f"repos/{repo}/rulesets/{rid}")
        if status != 200 or not isinstance(body, dict):
            print(f"  ruleset {rid}: NOT VERIFIED — GET repos/{repo}/rulesets/{rid} → status {status}")
            hidden_bypass.append(str(rid))
            continue
        bp = body.get("bypass_actors")
        def field(k, body=body):
            return json.dumps(body[k]) if k in body else "(absent from the response)"
        print(f"  ruleset {rid} {body.get('name')!r}: enforcement={field('enforcement')} "
              f"bypass_actors={field('bypass_actors')} current_user_can_bypass={field('current_user_can_bypass')}")
        if bp is None:
            print(f"    bypass_actors is {'absent' if 'bypass_actors' not in body else 'null'} for {cred}:"
                  " none and redacted cannot be told apart with this credential")
            hidden_bypass.append(str(rid))
    print("  gates — is the lane's job id a required context on the branch?")
    print(f"    {'lane':<26} " + " ".join(f"{b:<8}" for b in BRANCHES))
    for lane, requirable in sorted(lanes.items()):
        cells = []
        for b in BRANCHES:
            r = required[b]
            cell = "UNKNOWN" if r is None else ("yes" if lane in r else "no")
            cells.append(cell + ("!" if cell == "yes" and not requirable else ""))
        print(f"    {lane:<26} " + " ".join(f"{c:<8}" for c in cells))
    print("    (yes! = required but NEVER REQUIRABLE above: a PR it skips waits forever)")
    for b in BRANCHES:
        for ctx in sorted((required[b] or set()) - set(lanes)):
            print(f"    {b}: requires context {ctx!r}, which is no job id in this tree — it can never report")
    return hidden_bypass, [b for b in BRANCHES if required[b] is None]


def unverifiable_section(root: Path, hidden_bypass, unread_branches) -> None:
    print("\n== 3. NOT VERIFIABLE BY THIS SCRIPT (always printed) ==")
    cm = root / "CLAUDE.md"
    hits = ([f"CLAUDE.md:{n}: {l.strip()}" for n, l in enumerate(cm.read_text(encoding="utf-8").splitlines(), 1)
             if "ask-first" in l and "main" in l] if cm.is_file() else [])
    print("  - opening a release PR to main: a POLICY, not code, so nothing here can check it. Declared at:")
    for h in hits or ["NOT FOUND — no CLAUDE.md line names main and ask-first; read CLAUDE.md before acting"]:
        print(f"      {h}")
    print("  - organisation rulesets: this script reads the repository's branch rules and the rulesets they"
          " cite; whether an organisation ruleset exists that this credential cannot see is not established")
    print("  - whether the TARGET HOST meets the floors in § 1: only bin/deploy.sh's phase A (A1, A3b, A6,"
          " A12) knows, on that host, at deploy time")
    print("  - bypass actors: " + (f"not visible for ruleset(s) {', '.join(hidden_bypass)} — none and"
          " redacted cannot be told apart with this credential" if hidden_bypass else
          "visible for every ruleset read above") + (f"; the rulesets on {', '.join(unread_branches)}"
          " were not read at all, because that branch's read failed above" if unread_branches else ""))


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--root", type=Path, default=Path(__file__).resolve().parent.parent)
    ap.add_argument("--repo", default=os.environ.get("GITHUB_REPOSITORY", "PupFuzz/mezzanine"))
    ap.add_argument("--responses", type=Path, help="canned API responses (hermetic tests)")
    a = ap.parse_args(argv)
    cred, token, refused = credential()
    print(f"release-facts — {a.repo}, tree {a.root}")
    try:
        lanes, broken = tree_section(a.root)
    except (TreeError, OSError) as e:
        print(f"release-facts: cannot read the workflow tree — {e}", file=sys.stderr)
        return 2
    get = ((lambda path: (None, "not requested, the credential was refused")) if refused
           else make_get(a.responses, token))
    hidden, unread = live_section(a.repo, lanes, get, cred)
    unverifiable_section(a.root, hidden, unread)
    return 1 if broken else 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""release-facts.selftest.py — hermetic, network-free acceptance for bin/release-facts.py (card#9684).

Every case drives the REAL script as a subprocess over a SYNTHETIC workflow tree and SYNTHETIC API
bodies (`--responses`), and asserts on the PRINTED TEXT, never on an exit code alone. No network,
no credential, no repository settings: nothing here is a copy of this repo's rulesets.

RED-FIRST, IN THE SUITE. A case that has never failed is a decoration, so § 2 re-runs each case
against a mutated copy of the script — one edit that reintroduces the defect the case exists to
catch — and requires that case to FAIL there. A mutation whose target text is not in the script
exactly once is itself a failure, so a refactor cannot silently turn a control into a no-op.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent / "release-facts.py"
REPO = "o/r"
TOKEN = "ghp_selftestFAKEtoken0123456789abcdef"  # fed in, and must never come back out

WORKFLOWS = {
    "gate.yml": "name: Gate\non:\n  pull_request:\n    types: [opened]\n  push:\n    branches: [dev]\n"
                "jobs:\n  lint:\n    runs-on: x\n    steps:\n      - name: a step name is not a job name\n"
                "        run: |\n          name: not a key either\n  unit:\n    runs-on: x\n",
    "paths.yml": "on:\n  pull_request:\n    # a comment\n    paths:\n      - 'src/**'\njobs:\n  docs-only:\n    runs-on: x\n",
    "base.yml": "on:\n  pull_request:\n    branches: [dev]\njobs:\n  base-only:\n    runs-on: x\n",
    "push.yml": "on: [push]\njobs:\n  tagger:\n    runs-on: x\n",
}
CLAUDE = "# x\n**Merge.** Merging `main` is OPERATOR ONLY; OPENING a PR to it is ask-first.\n"


def rules(*contexts, extra=()):
    rsc = {"type": "required_status_checks", "ruleset_id": 1, "ruleset_source_type": "Repository",
           "ruleset_source": REPO, "parameters": {"required_status_checks": [{"context": c} for c in contexts]}}
    return [rsc, *extra]


def pr_rule(n):
    return {"type": "pull_request", "ruleset_id": 2, "parameters": {"required_approving_review_count": n}}


GOOD = {"dev": (200, rules("lint", "base-only")), "main": (200, rules("lint", "ghost", extra=(pr_rule(1), pr_rule(0))))}
RULESETS = {1: (200, {"name": "one", "bypass_actors": []}), 2: (200, {"name": "two", "bypass_actors": []})}


def run(script: Path, branches=GOOD, rulesets=RULESETS, workflows=WORKFLOWS, token=None):
    d = Path(tempfile.mkdtemp())
    wf = d / ".github" / "workflows"
    wf.mkdir(parents=True)
    for name, text in workflows.items():
        (wf / name).write_text(text)
    (d / "CLAUDE.md").write_text(CLAUDE)
    canned = {f"repos/{REPO}/rules/branches/{b}?per_page=100": {"status": s, "body": body}
              for b, (s, body) in branches.items()}
    canned.update({f"repos/{REPO}/rulesets/{i}": {"status": s, "body": body} for i, (s, body) in rulesets.items()})
    (d / "responses.json").write_text(json.dumps(canned))
    env = {k: v for k, v in os.environ.items() if k not in ("GH_TOKEN", "GITHUB_TOKEN")}
    if token:
        env["GH_TOKEN"] = token
    p = subprocess.run([sys.executable, str(script), "--root", str(d), "--repo", REPO,
                        "--responses", str(d / "responses.json")], capture_output=True, text=True, env=env)
    return p.returncode, p.stdout, p.stderr


def column(out: str, branch: str) -> dict:
    """The gates table, as {lane: cell} for one branch, parsed from the printed text. No table, or no
    column for the branch, is {} — so a case fails on its OWN assertion and never by crashing, which
    would let a broken mutant pass as a discriminating one."""
    lines = out.splitlines()
    head = next((i for i, l in enumerate(lines) if l.split()[:1] == ["lane"]), None)
    if head is None or branch not in lines[head].split():
        return {}
    idx = lines[head].split().index(branch)
    table = {}
    for l in lines[head + 1:]:
        cells = l.split()
        if not cells or cells[0].startswith("("):
            break
        table[cells[0]] = cells[idx]
    return table


def expect(fails, what, cond):
    if not cond:
        fails.append(what)


# ── the cases: each returns the list of its failed expectations (empty = pass) ──────────────────
def c_positive(s):
    f, (rc, out, err) = [], run(s)
    expect(f, "exit 0 on a clean tree", rc == 0)
    dev, main = column(out, "dev"), column(out, "main")
    expect(f, "lint gates dev", dev.get("lint") == "yes")
    expect(f, "unit does not gate dev", dev.get("unit") == "no")
    expect(f, "a required lane that is filtered is flagged yes!", dev.get("base-only") == "yes!")
    expect(f, "a required context no job reports is named", "requires context 'ghost'" in out)
    expect(f, "a step's name: and a run: block's text are not job keys", "CONTEXT != JOB ID" not in out)
    expect(f, "unauthenticated is named", "credential: unauthenticated" in out)
    return f


def c_empty_200(s):
    f, (rc, out, err) = [], run(s, branches={**GOOD, "dev": (200, [])})
    expect(f, "empty [] prints NOT VERIFIED", "dev: NOT VERIFIED" in out and "empty []" in out)
    dev = column(out, "dev")
    expect(f, "every dev cell is UNKNOWN, none is `no`", bool(dev) and set(dev.values()) == {"UNKNOWN"})
    expect(f, "main is still read", column(out, "main").get("lint") == "yes")
    return f


def c_403(s):
    f, (rc, out, err) = [], run(s, branches={**GOOD, "main": (403, None)})
    expect(f, "403 prints NOT VERIFIED naming the status", "main: NOT VERIFIED" in out and "status 403" in out)
    expect(f, "every main cell is UNKNOWN", set(column(out, "main").values()) == {"UNKNOWN"})
    expect(f, "a failed read does not change the exit", rc == 0)
    expect(f, "§ 3 says main's rulesets were never read", "the rulesets on main were not read at all" in out)
    return f


def c_404(s):
    f, (rc, out, err) = [], run(s, branches={**GOOD, "dev": (404, None)})
    expect(f, "404 prints NOT VERIFIED", "dev: NOT VERIFIED" in out and "status 404" in out)
    expect(f, "every dev cell is UNKNOWN", set(column(out, "dev").values()) == {"UNKNOWN"})
    return f


def c_named_job(s):
    wfs = {**WORKFLOWS, "named.yml": "on:\n  pull_request:\njobs:\n  renamed:\n    name: Pretty Name\n    runs-on: x\n"}
    f, (rc, out, err) = [], run(s, workflows=wfs)
    expect(f, "a job with name: exits nonzero", rc == 1)
    expect(f, "… naming the job and the file", "named.yml: job `renamed` declares `name:`" in out)
    return f


def c_flow_refused(s):
    wfs = {**WORKFLOWS, "flow.yml": "on:\n  pull_request: {paths: ['x/**']}\njobs:\n  hidden:\n    runs-on: x\n"}
    f, (rc, out, err) = [], run(s, workflows=wfs)
    expect(f, "a flow-mapping trigger is refused (exit 2), never read as unfiltered", rc == 2)
    expect(f, "… naming the file and why", "flow.yml" in err and "flow-mapping pull_request" in err)
    return f


def c_paths_filtered(s):
    f, (rc, out, err) = [], run(s)
    line = next((l for l in out.splitlines() if l.split()[:1] == ["docs-only"]), "")
    expect(f, "a paths:-filtered job is NEVER REQUIRABLE", "paths" in line and "NEVER REQUIRABLE" in line)
    line = next((l for l in out.splitlines() if l.split()[:1] == ["base-only"]), "")
    expect(f, "a branches:-filtered job is NEVER REQUIRABLE", "branches" in line and "NEVER REQUIRABLE" in line)
    line = next((l for l in out.splitlines() if l.split()[:1] == ["lint"]), "")
    expect(f, "an unfiltered job is not", "unfiltered" in line and "NEVER" not in line)
    line = next((l for l in out.splitlines() if l.split()[:1] == ["tagger"]), "")
    expect(f, "a job with no pull_request trigger is NEVER REQUIRABLE", "NEVER REQUIRABLE" in line)
    return f


def c_null_bypass(s):
    f, (rc, out, err) = [], run(s, rulesets={**RULESETS, 2: (200, {"name": "two", "bypass_actors": None})})
    expect(f, "null bypass_actors says none and redacted are indistinguishable",
           "none and redacted cannot be told apart" in out and "bypass_actors is null" in out)
    expect(f, "… and § 3 names that ruleset", "not visible for ruleset(s) 2" in out)
    return f


def c_no_token_leak(s):
    f, (rc, out, err) = [], run(s, token=TOKEN, branches={**GOOD, "main": (401, None)})
    expect(f, "the credential is named by its variable", "credential: GH_TOKEN (value not printed)" in out)
    expect(f, "the token value appears in neither stream", TOKEN not in out and TOKEN not in err)
    return f


def c_every_instance(s):
    f, (rc, out, err) = [], run(s)
    expect(f, "both pull_request instances on main are printed",
           '{"required_approving_review_count": 1}' in out and '{"required_approving_review_count": 0}' in out)
    return f


def c_no_floor_invented(s):
    f, (rc, out, err) = [], run(s)
    for lang in ("bash:", "npm:", "php:"):
        line = next((l for l in out.splitlines() if l.strip().startswith(lang)), "")
        expect(f, f"{lang} with no reader in the tree is NOT VERIFIED", "NOT VERIFIED" in line)
    expect(f, "the ask-first line is cited from CLAUDE.md", "CLAUDE.md:2: **Merge.**" in out)
    return f


CASES = {c.__name__: c for c in (c_positive, c_empty_200, c_403, c_404, c_named_job, c_flow_refused, c_paths_filtered,
                                  c_null_bypass, c_no_token_leak, c_every_instance, c_no_floor_invented)}

# (case that must red, text in the script, replacement that reintroduces the defect)
MUTANTS = [
    ("c_empty_200", " or not body or len(body)", " or len(body)"),  # [] read as "no rules"
    ("c_403", "            required[b] = None\n", "            required[b] = set()\n"),  # failed read = "nothing required"
    ("c_404", 'cell = "UNKNOWN" if r is None else', 'cell = "no" if r is None else'),
    ("c_named_job", 'CONTEXT_KEYS = ("name", "strategy", "uses")', 'CONTEXT_KEYS = ("strategy", "uses")'),
    ("c_flow_refused", '                    if r[2].startswith("{"):', "                    if False:"),
    ("c_paths_filtered",'PR_FILTERS = ("paths", "paths-ignore", "branches", "branches-ignore")',
     'PR_FILTERS = ("paths-ignore", "branches-ignore")'),
    ("c_null_bypass", "        if bp is None:\n", "        if False:\n"),
    ("c_no_token_leak", "return var, os.environ[var]", 'return f"{var}={os.environ[var]}", os.environ[var]'),
    ("c_every_instance", "for rule in body:", 'for rule in {r.get("type"): r for r in body}.values():'),
    ("c_no_floor_invented", "if rc == 0 and out:", "if True:"),
    ("c_positive", '("yes" if lane in r else "no")', '("yes" if lane in r else "UNKNOWN")'),
]


def main() -> int:
    fails = 0
    print("== 1. every case passes against the real script ==")
    for name, case in CASES.items():
        bad = case(SCRIPT)
        fails += bool(bad)
        print(f"  {'ok  ' if not bad else 'FAIL'} {name}" + "".join(f"\n       ✗ {b}" for b in bad))
    print("== 2. every case REDS against a script carrying the defect it guards ==")
    src = SCRIPT.read_text(encoding="utf-8")
    covered = set()
    for name, old, new in MUTANTS:
        if src.count(old) != 1:
            fails += 1
            print(f"  FAIL {name}: mutation target found {src.count(old)} times (want 1): {old!r}")
            continue
        m = Path(tempfile.mkdtemp()) / "release-facts.py"
        m.write_text(src.replace(old, new), encoding="utf-8")
        reds = bool(CASES[name](m))
        fails += not reds
        covered.add(name)
        print(f"  {'ok  ' if reds else 'FAIL'} {name} reds on {new.strip()!r}")
    for name in sorted(set(CASES) - covered):
        fails += 1
        print(f"  FAIL {name} has no mutant that proves it can fail")
    print(f"release-facts selftest: {len(CASES)} cases, {len(MUTANTS)} mutants, {fails} FAILED")
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())

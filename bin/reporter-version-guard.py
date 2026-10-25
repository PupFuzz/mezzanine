#!/usr/bin/env python3
"""reporter-version-guard.py — fail a PR that changes what a fleet-reporter update ships without
moving `REPORTER_VERSION`, or without saying in `fleet-reporter/UPDATES.md` what the update touches
(card#11818).

WHY IT EXISTS. Every seat running the reporter applies every reporter update (`fleet-reporter/README.md`
§ Updating a seat). That rule needs a seat to be able to tell which build it runs, and for the reporter's
first two months it could not: `REPORTER_VERSION` read `0.1.0` from the first build on while the file
changed in every later card, so every heartbeat named the same version whatever build sent it, and a
skipped update was silent on the seat and on the server. The constant was never moved because nothing
asked for it to be moved. This guard asks, on every PR.

THE POPULATION — what a reporter update ships, read from the runbook rather than listed here:
  * THE INSTALLED FILES. Every path or glob an `install -m 0600 <checkout>/<path> …` line of
    `fleet-reporter/INSTALL-LINUX.md` copies onto the seat (Step 1: today the script and the vendored
    hook fixtures). A path is the ARTIFACT; a glob is FIXTURES, and a change to the set of names a glob
    matches is HOOKS too, because Step 4 derives the hook set from those names.
  * THE CRONTAB ENTRY. The runbook's `LINE="…"` assignment and the `printf` that writes the
    `# BEGIN/END fleet-reporter-flusher` block (Step 5): a change there is CRON.
Read from the HEAD's runbook, so a new `install` line joins the population in the PR that adds it. A
runbook with no `install` line or no crontab entry is unmeasurable: exit 2, never a pass. So is a line
that installs from `<checkout>/` in any other form (`install -m 0640 <checkout>/…`, say): a reworded
line would otherwise drop out of the population silently, and the files it copies with it.

THE SERVER'S COPY OF THE VERSION, on EVERY PR whatever it touches: the head's
`server/app/Ingest/SchemaVersions.php` declares `CURRENT_REPORTER_VERSION` exactly once, equal to the
head's `REPORTER_VERSION` (card#11818 part 2; `docs/design/FLEET-STATE.md § 7.2` says what it badges).
Unequal is a finding (exit 1), so a PR that bumps the artifact alone and a PR that edits the constant
alone both fail; a missing file or a declaration other than once is unmeasurable (exit 2).

WHAT IT ASSERTS, when the PR changes any member of the population against the merge base:
  * the head's `REPORTER_VERSION` is an `x.y.z` strictly greater than the BASE TIP's, by numeric
    precedence. The base tip, not the merge base, so two PRs cut from one base cannot both land
    claiming the same next version;
  * `fleet-reporter/UPDATES.md` at the head holds one ledger row for that version, and the rows after
    the base's version up to it name, between them, at least every touch the diff shows (artifact,
    fixtures, hooks, cron), each spelled from `TOUCHES`. The union, because a seat on the base applies
    every one of those rows, and a PR carrying several bumps (a release PR) owes its diff across them.
`config` cannot be read off a diff — whether a release changes what a seat's config must hold is the
author's statement — so the guard checks only that it is spelled from the vocabulary.
A PR that changes no member is NOT APPLICABLE and exits 0.

WHAT IT CANNOT SEE: a change to what an upgrading seat must do that lives outside the population, such
as a new manual step written into the runbook's prose. The ledger row is where the author states one;
review is what catches a missing statement.

NO `paths:` FILTER IN ITS WORKFLOW (`.github/workflows/reporter-version.yml`): a path-filtered workflow
that does not run reads as pending, not passed (`docs/VERSIONING.md § Branch model`).

Usage: python3 bin/reporter-version-guard.py --base-rev=origin/dev [--head-rev=HEAD] [--repo=DIR]
       python3 bin/reporter-version-guard.py --selftest
Exit: 0 pass or not applicable · 1 a finding · 2 could not measure (a missing rev, file or declaration).
"""
from __future__ import annotations

import argparse
import fnmatch
import os
import re
import shutil
import subprocess
import sys
import tempfile

RUNBOOK = "fleet-reporter/INSTALL-LINUX.md"
SCRIPT = "fleet-reporter/fleet-reporter.js"
LEDGER = "fleet-reporter/UPDATES.md"
SERVER_VERSIONS = "server/app/Ingest/SchemaVersions.php"
TOUCHES = ("artifact", "fixtures", "hooks", "cron", "config")

INSTALL_RE = re.compile(r"^install -m 0600 <checkout>/(\S+) ", re.M)
INSTALL_ANY_RE = re.compile(r"^install\b.*<checkout>/.*$", re.M)
LINE_RE = re.compile(r'^\s*LINE="(.+)"\s*$', re.M)
CRON_BLOCK_RE = re.compile(r"^\s*printf '%s\\n' '# BEGIN fleet-reporter-flusher'.*?'# END fleet-reporter-flusher'.*?$",
                           re.M | re.S)
VERSION_RE = re.compile(r"^const REPORTER_VERSION = '([^']*)';$", re.M)
CURRENT_RE = re.compile(r"^\s*public const CURRENT_REPORTER_VERSION = '([^']*)';$", re.M)
LEDGER_RE = re.compile(r"<!-- reporter-updates:ledger BEGIN -->\n(.*?)<!-- reporter-updates:ledger END -->", re.S)
ROW_RE = re.compile(r"^\|\s*(\S+)\s*\|\s*([^|]*?)\s*\|")


class Unmeasurable(Exception):
    pass


def git(repo: str, *args: str) -> str:
    r = subprocess.run(["git", "-C", repo, *args], capture_output=True, text=True)
    if r.returncode != 0:
        raise Unmeasurable(f"git {' '.join(args)}: {r.stderr.strip() or 'exit ' + str(r.returncode)}")
    return r.stdout


def show(repo: str, rev: str, path: str) -> "str | None":
    r = subprocess.run(["git", "-C", repo, "show", f"{rev}:{path}"], capture_output=True, text=True)
    return r.stdout if r.returncode == 0 else None


def blobs(repo: str, rev: str) -> dict:
    out = git(repo, "ls-tree", "-r", rev, "--", "fleet-reporter/")
    m = {}
    for line in out.splitlines():
        meta, path = line.split("\t", 1)
        m[path] = meta.split()[2]
    return m


def matches(pattern: str, path: str) -> bool:
    # `*` stays inside one directory, as the shell glob in the runbook's `install` line does.
    return path.count("/") == pattern.count("/") and fnmatch.fnmatchcase(path, pattern)


def cron_entry(doc: str) -> str:
    return "\n".join(LINE_RE.findall(doc) + [m.group(0) for m in CRON_BLOCK_RE.finditer(doc)])


def parse_version(v: str) -> "tuple | None":
    m = re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", v)
    return tuple(int(x) for x in m.groups()) if m else None


def version_at(repo: str, rev: str) -> str:
    src = show(repo, rev, SCRIPT)
    if src is None:
        raise Unmeasurable(f"{SCRIPT} is not at {rev}")
    found = VERSION_RE.findall(src)
    if len(found) != 1:
        raise Unmeasurable(f"{SCRIPT} at {rev} declares REPORTER_VERSION {len(found)} times, not once")
    return found[0]


def current_at(repo: str, rev: str) -> str:
    src = show(repo, rev, SERVER_VERSIONS)
    if src is None:
        raise Unmeasurable(f"{SERVER_VERSIONS} is not at {rev}: CURRENT_REPORTER_VERSION is declared there")
    found = CURRENT_RE.findall(src)
    if len(found) != 1:
        raise Unmeasurable(f"{SERVER_VERSIONS} at {rev} declares CURRENT_REPORTER_VERSION {len(found)} times, not once")
    return found[0]


def judge(repo: str, base_rev: str, head_rev: str) -> "tuple[int, list[str]]":
    out: list = []
    head_doc = show(repo, head_rev, RUNBOOK)
    if head_doc is None:
        raise Unmeasurable(f"{RUNBOOK} is not at {head_rev}: the population is declared there")
    patterns = sorted(set(INSTALL_RE.findall(head_doc)))
    head_cron = cron_entry(head_doc)
    if not patterns:
        raise Unmeasurable(f"{RUNBOOK} at {head_rev} has no `install -m 0600 <checkout>/…` line: no population")
    if not LINE_RE.search(head_doc) or not CRON_BLOCK_RE.search(head_doc):
        raise Unmeasurable(f"{RUNBOOK} at {head_rev} has no crontab entry (`LINE=` and its `printf` block)")
    reworded = [l for l in INSTALL_ANY_RE.findall(head_doc) if not INSTALL_RE.match(l)]
    if reworded:
        raise Unmeasurable(f"{RUNBOOK} at {head_rev} installs from <checkout>/ in a line not in the form the guard "
                           f"reads (`install -m 0600 <checkout>/<path> …`), so its files are outside the population: "
                           + " | ".join(reworded))
    # The constant leg holds on every PR, so it is judged before the not-applicable return below.
    findings = []
    hv, sv = version_at(repo, head_rev), current_at(repo, head_rev)
    if sv != hv:
        findings.append(f"{SERVER_VERSIONS} declares CURRENT_REPORTER_VERSION '{sv}' and {SCRIPT} declares "
                        f"REPORTER_VERSION '{hv}' at the head: they move together, in the same PR "
                        "(docs/VERSIONING.md § The reporter's own version)")
    mb = git(repo, "merge-base", base_rev, head_rev).strip()
    out.append(f"population, from {RUNBOOK} at the head: {', '.join(patterns)}; the crontab entry")
    b, h = blobs(repo, mb), blobs(repo, head_rev)
    need = set()
    for p in patterns:
        kind = "fixtures" if any(c in p for c in "*?[") else "artifact"
        bp = {k: v for k, v in b.items() if matches(p, k)}
        hp = {k: v for k, v in h.items() if matches(p, k)}
        if kind == "artifact" and not hp:
            raise Unmeasurable(f"{p} is installed by the runbook and is not at {head_rev}")
        if bp != hp:
            need.add(kind)
            out.append(f"  changed: {p} ({kind})")
        if kind == "fixtures" and set(bp) != set(hp):
            need.add("hooks")
            out.append(f"  changed: the names {p} matches, so the hook set Step 4 wires (hooks)")
    if cron_entry(show(repo, mb, RUNBOOK) or "") != head_cron:
        need.add("cron")
        out.append("  changed: the crontab entry Step 5 writes (cron)")
    if not need:
        if findings:
            out += ["FAIL: " + f for f in findings]
            return 1, out
        out.append(f"CURRENT_REPORTER_VERSION equals REPORTER_VERSION at the head: '{hv}'")
        out.append("NOT APPLICABLE: this PR changes nothing a reporter update ships")
        return 0, out

    bv = version_at(repo, base_rev)
    hp_, bp_ = parse_version(hv), parse_version(bv)
    if hp_ is None:
        findings.append(f"REPORTER_VERSION '{hv}' at the head is not an x.y.z version")
    elif bp_ is None:
        raise Unmeasurable(f"REPORTER_VERSION '{bv}' at {base_rev} is not an x.y.z version")
    elif hp_ == bp_:
        findings.append(f"REPORTER_VERSION is '{hv}' at the head and on the base: UNCHANGED, so this build "
                        "would report the same version as the one before it. Bump it (docs/VERSIONING.md "
                        "§ The reporter's own version)")
    elif hp_ < bp_:
        findings.append(f"REPORTER_VERSION '{hv}' at the head is LOWER than the base's '{bv}'")
    else:
        out.append(f"REPORTER_VERSION: '{bv}' on the base, '{hv}' at the head")

    ledger = show(repo, head_rev, LEDGER)
    block = LEDGER_RE.search(ledger or "")
    if block is None:
        findings.append(f"{LEDGER} at the head has no `reporter-updates:ledger` block")
    else:
        rows = [ROW_RE.match(l) for l in block.group(1).splitlines()]
        rows = [r for r in rows if r and parse_version(r.group(1))]
        mine = [r for r in rows if r.group(1) == hv]
        # Every row after the base's version: a seat on the base applies all of them, and a PR that
        # carries several bumps (a release PR does) owes its diff's touches across their union.
        span = [r for r in rows if hp_ and bp_ and bp_ < parse_version(r.group(1)) <= hp_]
        got = {t.strip() for r in span for t in r.group(2).split(",") if t.strip()}
        unknown = sorted(got - set(TOUCHES))
        missing = [t for t in TOUCHES if t in need and t not in got]
        if len(mine) != 1:
            findings.append(f"{LEDGER} holds {len(mine)} ledger rows for '{hv}', not one: say what this update touches")
        elif unknown:
            findings.append(f"the ledger rows after '{bv}' name touches outside {', '.join(TOUCHES)}: {', '.join(unknown)}")
        elif missing:
            findings.append(f"the ledger rows after '{bv}' up to '{hv}' do not name what this PR changes: {', '.join(missing)}")
        else:
            out.append(f"ledger rows after '{bv}' up to '{hv}': touches {', '.join(t for t in TOUCHES if t in got)}")
    if findings:
        out += ["FAIL: " + f for f in findings]
        return 1, out
    out.append("PASS: the update moves REPORTER_VERSION and its ledger row names what it touches")
    return 0, out


def run(repo: str, base_rev: str, head_rev: str) -> int:
    try:
        rc, lines = judge(repo, base_rev, head_rev)
    except Unmeasurable as e:
        print(f"UNMEASURED: {e}", file=sys.stderr)
        return 2
    print("\n".join(lines))
    return rc


# ── selftest ─────────────────────────────────────────────────────────────────────────────────────
# Each case is a branch off a base commit that holds THIS checkout's real runbook, script, fixtures
# and ledger, so the parsers are exercised against the files they will read, not a sketch of them.

def selftest() -> int:
    here = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    tmp = tempfile.mkdtemp(prefix="reporter-version-guard-")
    env = dict(os.environ, GIT_AUTHOR_NAME="selftest", GIT_AUTHOR_EMAIL="selftest@invalid",
               GIT_COMMITTER_NAME="selftest", GIT_COMMITTER_EMAIL="selftest@invalid")

    def g(*a):
        subprocess.run(["git", "-C", tmp, *a], check=True, capture_output=True, env=env)

    def rd(p):
        with open(os.path.join(tmp, p), encoding="utf-8") as f:
            return f.read()

    def wr(p, s):
        os.makedirs(os.path.dirname(os.path.join(tmp, p)), exist_ok=True)
        with open(os.path.join(tmp, p), "w", encoding="utf-8") as f:
            f.write(s)

    results = []
    try:
        g("init", "-q", "-b", "base")
        for rel in (RUNBOOK, SCRIPT, LEDGER, SERVER_VERSIONS):
            wr(rel, open(os.path.join(here, rel), encoding="utf-8").read())
        fx = "fleet-reporter/fixtures/hooks"
        for n in sorted(os.listdir(os.path.join(here, fx))):
            wr(f"{fx}/{n}", open(os.path.join(here, fx, n), encoding="utf-8").read())
        wr("README.md", "unrelated\n")
        g("add", "-A"); g("commit", "-q", "-m", "base")
        cur = VERSION_RE.search(rd(SCRIPT)).group(1)
        x, y, z = parse_version(cur)
        nxt = f"{x}.{y}.{z + 1}"

        # Every bump moves the server's constant with the artifact, as a real bump PR must; the one case
        # that plants the constant left behind uses `bump_artifact_only`.
        def bump_artifact_only(v):
            wr(SCRIPT, VERSION_RE.sub(f"const REPORTER_VERSION = '{v}';", rd(SCRIPT)))

        def bump(v):
            bump_artifact_only(v)
            wr(SERVER_VERSIONS, CURRENT_RE.sub(f"    public const CURRENT_REPORTER_VERSION = '{v}';", rd(SERVER_VERSIONS)))

        def row(v, touches):
            wr(LEDGER, rd(LEDGER).replace("<!-- reporter-updates:ledger END -->",
                                          f"| {v} | {touches} | selftest |\n<!-- reporter-updates:ledger END -->"))

        def case(name, want_rc, want_text, edit):
            g("checkout", "-q", "-B", name, "base")
            edit()
            g("add", "-A"); g("commit", "-q", "--allow-empty", "-m", name)
            r = subprocess.run([sys.executable, os.path.abspath(__file__), f"--repo={tmp}", "--base-rev=base",
                                f"--head-rev={name}"], capture_output=True, text=True)
            text = r.stdout + r.stderr
            ok = r.returncode == want_rc and want_text in text
            results.append(ok)
            print(f"{'ok  ' if ok else 'BAD '} {name}: rc={r.returncode} (want {want_rc}), "
                  f"{'found' if want_text in text else 'MISSING'} {want_text!r}")
            if not ok:
                print("     " + text.replace("\n", "\n     "))

        js_edit = lambda: wr(SCRIPT, rd(SCRIPT) + "\n// selftest edit\n")
        case("control-unrelated", 0, "NOT APPLICABLE", lambda: wr("README.md", "changed\n"))
        case("plant-js-no-bump", 1, "UNCHANGED", js_edit)
        case("control-js-bump-row", 0, "PASS", lambda: (js_edit(), bump(nxt), row(nxt, "artifact")))
        case("plant-bump-no-row", 1, f"0 ledger rows for '{nxt}'", lambda: (js_edit(), bump(nxt)))
        case("plant-fixture-content-no-bump", 1, "UNCHANGED",
             lambda: wr(f"{fx}/Stop.json", rd(f"{fx}/Stop.json") + "\n"))
        case("plant-fixture-added-row-lacks-hooks", 1, "do not name what this PR changes: fixtures, hooks",
             lambda: (wr(f"{fx}/NewHook.json", '{"shapes": []}\n'), bump(nxt), row(nxt, "artifact")))
        case("control-fixture-added-row-names-hooks", 0, "PASS",
             lambda: (wr(f"{fx}/NewHook.json", '{"shapes": []}\n'), bump(nxt), row(nxt, "artifact, fixtures, hooks")))
        case("plant-cron-changed-row-lacks-cron", 1, "do not name what this PR changes: cron",
             lambda: (wr(RUNBOOK, rd(RUNBOOK).replace(' flusher >/dev/null 2>&1"', ' flusher >/dev/null 2>&1 "', 1)),
                      bump(nxt), row(nxt, "artifact")))
        case("plant-version-lowered", 1, "LOWER",
             lambda: (js_edit(), bump(f"{x}.{y}.{z - 1}" if z else "0.0.0"), row(f"{x}.{y}.{z - 1}" if z else "0.0.0", "artifact")))
        case("plant-touch-unknown", 1, "outside", lambda: (js_edit(), bump(nxt), row(nxt, "artifact, everything")))
        case("plant-no-population", 2, "no `install -m 0600",
             lambda: wr(RUNBOOK, rd(RUNBOOK).replace("install -m 0600 <checkout>/", "install -m 0644 <checkout>/")))
        case("plant-install-line-reworded", 2, "not in the form the guard reads",
             lambda: wr(RUNBOOK, rd(RUNBOOK).replace("install -m 0600 <checkout>/", "install -m 0640 <checkout>/", 1)))
        case("plant-bump-server-constant-stale", 1, "CURRENT_REPORTER_VERSION",
             lambda: (js_edit(), bump_artifact_only(nxt), row(nxt, "artifact")))
        case("plant-server-constant-missing", 2, "CURRENT_REPORTER_VERSION 0 times",
             lambda: wr(SERVER_VERSIONS, CURRENT_RE.sub("", rd(SERVER_VERSIONS))))

        # Two bumps on one branch, as a release PR carries: the diff's touches are owed across both rows.
        nn = f"{x}.{y}.{z + 2}"

        def two_bumps(first_touches):
            def edit():
                wr(f"{fx}/NewHook.json", '{"shapes": []}\n'); bump(nxt); row(nxt, first_touches)
                g("add", "-A"); g("commit", "-q", "-m", "first bump")
                js_edit(); bump(nn); row(nn, "artifact")
            return edit
        case("control-two-bumps-union-covers", 0, "PASS", two_bumps("artifact, fixtures, hooks"))
        case("plant-two-bumps-union-lacks-hooks", 1, "do not name what this PR changes: fixtures, hooks",
             two_bumps("artifact"))

        # The base moved after the branch was cut and took the same next version: the head's bump is
        # no longer greater than the base TIP's, even though it is greater than the merge base's.
        g("checkout", "-q", "-B", "plant-base-took-the-version", "base")
        js_edit(); bump(nxt); row(nxt, "artifact")
        g("add", "-A"); g("commit", "-q", "-m", "head")
        g("checkout", "-q", "base")
        wr(SCRIPT, rd(SCRIPT) + "\n// another PR\n"); bump(nxt); row(nxt, "artifact")
        g("add", "-A"); g("commit", "-q", "-m", "another PR landed")
        r = subprocess.run([sys.executable, os.path.abspath(__file__), f"--repo={tmp}", "--base-rev=base",
                            "--head-rev=plant-base-took-the-version"], capture_output=True, text=True)
        ok = r.returncode == 1 and "UNCHANGED" in r.stdout
        results.append(ok)
        print(f"{'ok  ' if ok else 'BAD '} plant-base-took-the-version: rc={r.returncode} (want 1)")
    finally:
        shutil.rmtree(tmp, ignore_errors=True)
    bad = results.count(False)
    print(f"selftest: {len(results) - bad} of {len(results)} cases as expected" + ("" if not bad else " — FAILED"))
    return 1 if bad else 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--base-rev")
    ap.add_argument("--head-rev", default="HEAD")
    ap.add_argument("--repo", default=".")
    a = ap.parse_args()
    if a.selftest:
        return selftest()
    if not a.base_rev:
        ap.error("--base-rev is required")
    return run(a.repo, a.base_rev, a.head_rev)


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""reporter-version-guard.py — fail a PR that changes what a fleet-reporter update ships without
moving `REPORTER_VERSION`, or without saying in `fleet-reporter/UPDATES.md` what the update touches
(card#11818; the population re-homed to `install.js` by card#12571, D6 § 3.3).

WHY IT EXISTS. Every seat running the reporter applies every reporter update (`fleet-reporter/README.md`
§ Updating a seat). That rule needs a seat to be able to tell which build it runs, and for the reporter's
first two months it could not: `REPORTER_VERSION` read `0.1.0` from the first build on while the file
changed in every later card, so every heartbeat named the same version whatever build sent it, and a
skipped update was silent on the seat and on the server. The constant was never moved because nothing
asked for it to be moved. This guard asks, on every PR.

THE POPULATION — what a reporter update ships, read from the installer rather than listed here, in two parts:
  * THE INSTALLED FILES. The quoted strings of `fleet-reporter/install.js`'s one marked region,
    `// reporter-version-guard: BEGIN artifact` … `END artifact`, each a path under `fleet-reporter/`
    (today the script and the vendored hook fixtures). A plain path is the ARTIFACT; a glob is FIXTURES,
    and a change to the set of names a glob matches is HOOKS too, because the hook set is derived from them.
    Read from the HEAD's region, so a new file joins the population in the PR that adds it.
  * THE START DEFINITIONS AND THE HOOK WIRING, COMPARED BY OUTPUT, NOT BY TEXT. `node install.js
    --render-shipping` prints, for every platform, the crontab block, the launchd plist and the hook command
    set a seat installs. The guard runs it at the merge base and at the head, from one fixed JSON input on
    stdin, and diffs: a crontab block or a plist that differs is SUPERVISOR; a hook command, matcher or the
    statusline command that differs is HOOKS — so a helper edited anywhere in the file that changes what a
    seat installs is seen, whether or not a region encloses it. The render takes the platform as an argument
    (the guard sees a win32-only edit from Linux), spawns nothing and writes nothing; the guard runs it with
    a temporary HOME and a PATH that holds no directory at all, `node` by its absolute path, so a render
    that tried to run `crontab` or `launchctl` fails instead of reaching one.
  * A REVISION WITH NO `install.js` — the build PR's own base, card#12571 — reads as the population before
    it: the `install -m 0600 <checkout>/…` lines of `fleet-reporter/INSTALL-LINUX.md` for the files, and its
    crontab entry (`LINE="…"` and the `printf` of the `# BEGIN/END fleet-reporter-flusher` block), expanded
    HERE, in Python, for the same input, as the Linux crontab block. Nothing else renders at such a revision,
    so every other render key is a difference at the head. Never exit 2 for that base: it is the measurement
    of the PR that adds install.js, and `reporter-version` is a required check.
Exit 2 — never a pass — at a revision that HAS install.js but no single `artifact` region, an empty one, or
a render that fails; at a revision with neither install.js nor the runbook's population; and on a missing rev.

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
    fixtures, hooks, supervisor), each spelled from `TOUCHES` — `cron`, the 0.2.0 row's name for
    `supervisor`, is read as it. The union, because a seat on the base applies every one of those rows.
`config` cannot be read off a diff — whether a release changes what a seat's config must hold is the
author's statement — so the guard checks only that it is spelled from the vocabulary.
A PR that changes no member is NOT APPLICABLE and exits 0.

WHAT IT CANNOT SEE: a change to what an upgrading seat must do that neither installs a file nor changes the
render — a new step whose effect is a config key, a settings key the render does not print, or a refusal.
The ledger row is where the author states one; review is what catches a missing statement. The render's
input is a contract between revisions and is append-only: unknown keys are ignored, so the head may read a
key a base does not, and removing one takes two PRs (`install.js`'s `renderShipping` says why).

NO `paths:` FILTER IN ITS WORKFLOW (`.github/workflows/reporter-version.yml`): a path-filtered workflow
that does not run reads as pending, not passed (`docs/VERSIONING.md § Branch model`).

Usage: python3 bin/reporter-version-guard.py --base-rev=origin/dev [--head-rev=HEAD] [--repo=DIR]
       python3 bin/reporter-version-guard.py --selftest
Exit: 0 pass or not applicable · 1 a finding · 2 could not measure (a missing rev, file, region or render).
"""
from __future__ import annotations

import argparse
import fnmatch
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile

INSTALLER = "fleet-reporter/install.js"
RUNBOOK = "fleet-reporter/INSTALL-LINUX.md"
SCRIPT = "fleet-reporter/fleet-reporter.js"
FIXTURES = "fleet-reporter/fixtures/hooks"
LEDGER = "fleet-reporter/UPDATES.md"
SERVER_VERSIONS = "server/app/Ingest/SchemaVersions.php"
TOUCHES = ("artifact", "fixtures", "hooks", "supervisor", "config")
ALIASES = {"cron": "supervisor"}

REGION_BEGIN = "// reporter-version-guard: BEGIN artifact"
REGION_END = "// reporter-version-guard: END artifact"
INSTALL_RE = re.compile(r"^install -m 0600 <checkout>/(\S+) ", re.M)
LINE_RE = re.compile(r'^\s*LINE="(.+)"\s*$', re.M)
CRON_BLOCK_RE = re.compile(r"^\s*printf '%s\\n' '# BEGIN fleet-reporter-flusher'.*?'# END fleet-reporter-flusher'", re.M | re.S)
VERSION_RE = re.compile(r"^const REPORTER_VERSION = '([^']*)';$", re.M)
CURRENT_RE = re.compile(r"^\s*public const CURRENT_REPORTER_VERSION = '([^']*)';$", re.M)
LEDGER_RE = re.compile(r"<!-- reporter-updates:ledger BEGIN -->\n(.*?)<!-- reporter-updates:ledger END -->", re.S)
ROW_RE = re.compile(r"^\|\s*(\S+)\s*\|\s*([^|]*?)\s*\|")

# The render's input: one seat's values, the same at the base and at the head.
HOME, WIN_HOME, NODE_AT, CC = "/home/agent", "C:\\Users\\agent", "/usr/bin/node", "/home/agent/.config/coord/coordination.config.json"


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
    # `*` stays inside one directory, as the shell glob the installer's list models does.
    return path.count("/") == pattern.count("/") and fnmatch.fnmatchcase(path, pattern)


def region_patterns(src: str, rev: str) -> list:
    b, e = src.count(REGION_BEGIN), src.count(REGION_END)
    if b != 1 or e != 1:
        raise Unmeasurable(f"{INSTALLER} at {rev} holds {b} `{REGION_BEGIN}` and {e} `{REGION_END}` markers, not one "
                           "each: the files a seat installs cannot be read")
    body = src[src.index(REGION_BEGIN) + len(REGION_BEGIN):src.index(REGION_END)]
    found = ["fleet-reporter/" + (a or b_) for a, b_ in re.findall(r"'([^']+)'|\"([^\"]+)\"", body)]
    if not found:
        raise Unmeasurable(f"{INSTALLER} at {rev}: the `artifact` region names no file")
    return sorted(set(found))


def render_input(repo: str, rev: str) -> dict:
    names, shapes = [], {}
    for path in sorted(blobs(repo, rev)):
        if path.startswith(FIXTURES + "/") and path.endswith(".json") and path.count("/") == 3:
            n = path.rsplit("/", 1)[1][:-5]
            names.append(n)
            try:
                shapes[n] = json.loads(show(repo, rev, path) or "{}").get("shapes", [])
            except ValueError:
                shapes[n] = []
    return {"home": HOME, "win32_home": WIN_HOME, "node": NODE_AT, "coord_config": CC, "fixtures": names, "fixture_shapes": shapes}


def render(src: str, rev: str, inp: dict) -> dict:
    """`install.js --render-shipping` from the revision's own text, with a temporary HOME and an empty PATH."""
    node = shutil.which("node")
    if not node:
        raise Unmeasurable("no `node` on PATH: the render cannot run")
    node = os.path.realpath(node)
    tmp = tempfile.mkdtemp(prefix="reporter-version-render-")
    try:
        home, empty = os.path.join(tmp, "home"), os.path.join(tmp, "path")
        os.makedirs(home)
        os.makedirs(empty)
        js = os.path.join(tmp, "install.js")
        with open(js, "w", encoding="utf-8") as f:
            f.write(src)
        r = subprocess.run([node, js, "--render-shipping"], input=json.dumps(inp), capture_output=True, text=True,
                           env={"HOME": home, "PATH": empty}, timeout=60)
        if r.returncode != 0:
            raise Unmeasurable(f"`install.js --render-shipping` at {rev} failed (exit {r.returncode}): {(r.stderr or r.stdout).strip()[:400]}")
        if os.listdir(home):
            raise Unmeasurable(f"`install.js --render-shipping` at {rev} wrote into its HOME: the render must have no side effects")
        try:
            return json.loads(r.stdout)
        except ValueError:
            raise Unmeasurable(f"`install.js --render-shipping` at {rev} did not print one JSON object")
    finally:
        shutil.rmtree(tmp, ignore_errors=True)


def expand(word: str, env: dict) -> str:
    """POSIX parameter expansion of the two forms the runbook's crontab entry uses: `${V:+text}` and `$V`."""
    def plus(m):
        return expand(m.group(2), env) if env.get(m.group(1)) else ""
    word = re.sub(r"\$\{([A-Z_]+):\+([^}]*)\}", plus, word)
    return re.sub(r"\$([A-Z_]+)", lambda m: env.get(m.group(1), ""), word)


def legacy_render(doc: str, rev: str, inp: dict) -> dict:
    """The runbook's crontab block for the render's input, as its Step 5 wrote it — by substitution, never by
    running the revision's shell text."""
    line = LINE_RE.search(doc)
    blk = CRON_BLOCK_RE.search(doc)
    if not line or not blk:
        raise Unmeasurable(f"{RUNBOOK} at {rev} has no crontab entry (`LINE=` and its `printf` block)")
    env = {"CC": inp["coord_config"], "FLOCK": HOME + "/.local/state/fleet-reporter-flusher.flock", "NODE": inp["node"],
           "JS": HOME + "/.local/share/fleet-reporter/fleet-reporter.js"}
    env["LINE"] = expand(line.group(1), env)
    words = re.findall(r"'([^']*)'|\"([^\"]*)\"", blk.group(0).split("'%s\\n'", 1)[1])
    lines = [s if s or not d else expand(d, env) for s, d in words]
    return {"linux": {"crontab_block": "\n".join(lines) + "\n"}}


def population(repo: str, rev: str, inp: dict) -> "tuple[list, dict]":
    """(the file patterns, the render) at `rev`."""
    src = show(repo, rev, INSTALLER)
    if src is not None:
        return region_patterns(src, rev), render(src, rev, inp)
    doc = show(repo, rev, RUNBOOK)
    pats = sorted(set("fleet-reporter/" + p if not p.startswith("fleet-reporter/") else p for p in INSTALL_RE.findall(doc or "")))
    if doc is None or not pats:
        raise Unmeasurable(f"neither {INSTALLER} nor {RUNBOOK}'s `install -m 0600 <checkout>/…` lines are at {rev}: no population")
    return pats, legacy_render(doc, rev, inp)


def render_touches(base: dict, head: dict) -> "list[tuple[str, str]]":
    out = []
    for p in sorted(set(base) | set(head)):
        b, h = base.get(p, {}), head.get(p, {})
        for k in sorted(set(b) | set(h)):
            if b.get(k) != h.get(k):
                out.append((("supervisor" if k in ("crontab_block", "plist") else "hooks"), f"{p} {k}"))
    return out


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
    mb = git(repo, "merge-base", base_rev, head_rev).strip()
    inp = render_input(repo, head_rev)
    patterns, head_render = population(repo, head_rev, inp)
    _, base_render = population(repo, mb, inp)
    # The constant leg holds on every PR, so it is judged before the not-applicable return below.
    findings = []
    hv, sv = version_at(repo, head_rev), current_at(repo, head_rev)
    if sv != hv:
        findings.append(f"{SERVER_VERSIONS} declares CURRENT_REPORTER_VERSION '{sv}' and {SCRIPT} declares "
                        f"REPORTER_VERSION '{hv}' at the head: they move together, in the same PR "
                        "(docs/VERSIONING.md § The reporter's own version)")
    out.append(f"population, from {INSTALLER if show(repo, head_rev, INSTALLER) is not None else RUNBOOK} at the head: "
               f"{', '.join(patterns)}; the --render-shipping output for every platform")
    b, h = blobs(repo, mb), blobs(repo, head_rev)
    need = set()
    for p in patterns:
        kind = "fixtures" if any(c in p for c in "*?[") else "artifact"
        bp = {k: v for k, v in b.items() if matches(p, k)}
        hp = {k: v for k, v in h.items() if matches(p, k)}
        if kind == "artifact" and not hp:
            raise Unmeasurable(f"{p} is installed and is not at {head_rev}")
        if bp != hp:
            need.add(kind)
            out.append(f"  changed: {p} ({kind})")
        if kind == "fixtures" and set(bp) != set(hp):
            need.add("hooks")
            out.append(f"  changed: the names {p} matches, so the hook set the installer wires (hooks)")
    for touch, where in render_touches(base_render, head_render):
        need.add(touch)
        out.append(f"  changed: what --render-shipping prints for {where} ({touch})")
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
        got = {ALIASES.get(t.strip(), t.strip()) for r in span for t in r.group(2).split(",") if t.strip()}
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
# Each case is a branch off a base commit that holds THIS checkout's real installer, script, fixtures,
# ledger and server constant, so the parsers and the render are exercised against the files they will read,
# not a sketch of them. The base-without-install.js case reads the runbook as it stood at 188e5fce, the
# card#12571 build's base, from this repository's own history.

LEGACY_REV = "188e5fce"


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

    def sub(p, old, new):
        t = rd(p)
        if old not in t:
            raise AssertionError(f"selftest plant does not match {p}: {old[:80]!r}")
        wr(p, t.replace(old, new, 1))

    results = []
    try:
        g("init", "-q", "-b", "base")
        for rel in (INSTALLER, SCRIPT, LEDGER, SERVER_VERSIONS):
            wr(rel, open(os.path.join(here, rel), encoding="utf-8").read())
        for n in sorted(os.listdir(os.path.join(here, FIXTURES))):
            wr(f"{FIXTURES}/{n}", open(os.path.join(here, FIXTURES, n), encoding="utf-8").read())
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

        def verdict(name, base="base"):
            r = subprocess.run([sys.executable, os.path.abspath(__file__), f"--repo={tmp}", f"--base-rev={base}",
                                f"--head-rev={name}"], capture_output=True, text=True)
            return r.returncode, r.stdout + r.stderr

        def case(name, want_rc, want_text, edit, base="base"):
            g("checkout", "-q", "-B", name, base)
            edit()
            g("add", "-A"); g("commit", "-q", "--allow-empty", "-m", name)
            rc, text = verdict(name, base)
            ok = rc == want_rc and want_text in text
            results.append(ok)
            print(f"{'ok  ' if ok else 'BAD '} {name}: rc={rc} (want {want_rc}), "
                  f"{'found' if want_text in text else 'MISSING'} {want_text!r}")
            if not ok:
                print("     " + text.replace("\n", "\n     "))

        js_edit = lambda: wr(SCRIPT, rd(SCRIPT) + "\n// selftest edit\n")
        fx = FIXTURES
        case("control-unrelated", 0, "NOT APPLICABLE", lambda: wr("README.md", "changed\n"))
        case("control-installer-comment-only", 0, "NOT APPLICABLE", lambda: wr(INSTALLER, rd(INSTALLER) + "\n// a comment changes no output\n"))
        case("plant-js-no-bump", 1, "UNCHANGED", js_edit)
        case("control-js-bump-row", 0, "PASS", lambda: (js_edit(), bump(nxt), row(nxt, "artifact")))
        case("plant-bump-no-row", 1, f"0 ledger rows for '{nxt}'", lambda: (js_edit(), bump(nxt)))
        case("plant-fixture-content-no-bump", 1, "UNCHANGED", lambda: wr(f"{fx}/Stop.json", rd(f"{fx}/Stop.json") + "\n"))
        case("plant-fixture-added-row-lacks-hooks", 1, "do not name what this PR changes: fixtures, hooks",
             lambda: (wr(f"{fx}/NewHook.json", '{"shapes": []}\n'), bump(nxt), row(nxt, "artifact")))
        case("control-fixture-added-row-names-hooks", 0, "PASS",
             lambda: (wr(f"{fx}/NewHook.json", '{"shapes": []}\n'), bump(nxt), row(nxt, "artifact, fixtures, hooks")))
        # R-all-14 — a helper edited OUTSIDE the artifact region, changing what a seat installs.
        case("plant-cron-helper-row-lacks-supervisor", 1, "do not name what this PR changes: supervisor",
             lambda: (sub(INSTALLER, "flusher >/dev/null 2>&1`;", "flusher > /dev/null 2>&1`;"), bump(nxt), row(nxt, "artifact")))
        case("plant-plist-helper-no-bump", 1, "UNCHANGED", lambda: sub(INSTALLER, "<integer>60</integer>", "<integer>30</integer>"))
        case("plant-hook-helper-row-lacks-hooks", 1, "do not name what this PR changes: hooks",
             lambda: (sub(INSTALLER, "  return `node ${artifactPath} ${sub}`;", "  return `node --no-warnings ${artifactPath} ${sub}`;"),
                      bump(nxt), row(nxt, "artifact")))
        case("plant-matcher-rule-no-bump", 1, "UNCHANGED", lambda: sub(INSTALLER, "typeof p.tool_name === 'string') ? '*' : ''", "typeof p.tool_name === 'string') ? '' : '*'"))
        case("control-cron-helper-row-names-supervisor", 0, "PASS",
             lambda: (sub(INSTALLER, "flusher >/dev/null 2>&1`;", "flusher > /dev/null 2>&1`;"), bump(nxt), row(nxt, "artifact, supervisor")))
        case("control-cron-row-spelled-cron", 0, "PASS",
             lambda: (sub(INSTALLER, "flusher >/dev/null 2>&1`;", "flusher > /dev/null 2>&1`;"), bump(nxt), row(nxt, "artifact, cron")))
        # R-all-20 — the win32 branch alone, judged on Linux.
        case("plant-win32-hook-branch-row-lacks-hooks", 1, "win32 hooks",
             lambda: (sub(INSTALLER, "return `node \"${artifactPath.split('\\\\').join('/')}\" ${sub}`;",
                          "return `node \"${artifactPath}\" ${sub}`;"), bump(nxt), row(nxt, "artifact")))
        # Exit 2 at a revision that has install.js.
        case("plant-region-missing", 2, "markers, not one each", lambda: sub(INSTALLER, REGION_BEGIN + "\n", ""))
        case("plant-region-duplicated", 2, "markers, not one each", lambda: wr(INSTALLER, rd(INSTALLER) + "\n" + REGION_BEGIN + "\n" + REGION_END + "\n"))
        case("plant-render-fails", 2, "--render-shipping` at", lambda: sub(INSTALLER, "function renderShipping(input) {", "function renderShipping(input) { throw new Error('planted');"))
        case("plant-render-spawns", 2, "--render-shipping` at",
             lambda: sub(INSTALLER, "function renderShipping(input) {", "function renderShipping(input) { if (cp.spawnSync('crontab', ['-l']).error) throw new Error('spawned');"))
        case("plant-version-lowered", 1, "LOWER",
             lambda: (js_edit(), bump(f"{x}.{y}.{z - 1}" if z else "0.0.0"), row(f"{x}.{y}.{z - 1}" if z else "0.0.0", "artifact")))
        case("plant-touch-unknown", 1, "outside", lambda: (js_edit(), bump(nxt), row(nxt, "artifact, everything")))
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
        case("plant-two-bumps-union-lacks-hooks", 1, "do not name what this PR changes: fixtures, hooks", two_bumps("artifact"))

        # R-all-19 — the build PR's own shape: a base WITHOUT install.js, holding the runbook as it stood at
        # 188e5fce. The head reproduces its crontab block, so the Linux crontab owes nothing; the plist and the
        # hook commands have no counterpart there, so they owe supervisor and hooks.
        legacy = subprocess.run(["git", "-C", here, "show", f"{LEGACY_REV}:{RUNBOOK}"], capture_output=True, text=True)
        if legacy.returncode != 0:
            print(f"BAD  base-without-install.js: the runbook at {LEGACY_REV} is not in this clone's history "
                  "(a shallow clone): NOT MEASURED, which is not a pass")
            results.append(False)
        else:
            g("checkout", "-q", "-B", "legacy-base", "base")
            g("rm", "-q", INSTALLER)
            wr(RUNBOOK, legacy.stdout)
            g("add", "-A"); g("commit", "-q", "-m", "a base without install.js")

            def build(touches):
                def edit():
                    wr(INSTALLER, open(os.path.join(here, INSTALLER), encoding="utf-8").read())
                    js_edit(); bump(nxt); row(nxt, touches)
                return edit
            case("control-base-without-installer-row-covers", 0, "PASS", build("artifact, supervisor, hooks"), base="legacy-base")
            case("plant-base-without-installer-row-lacks", 1, "do not name what this PR changes: hooks, supervisor",
                 build("artifact"), base="legacy-base")
            g("checkout", "-q", "-B", "probe-legacy-linux", "legacy-base")
            wr(INSTALLER, open(os.path.join(here, INSTALLER), encoding="utf-8").read())
            g("add", "-A"); g("commit", "-q", "-m", "probe")
            rc, text = verdict("probe-legacy-linux", "legacy-base")
            ok = rc != 2 and "linux crontab_block" not in text and "darwin plist" in text
            results.append(ok)
            print(f"{'ok  ' if ok else 'BAD '} base-without-install.js never exits 2, and the head reproduces its Linux crontab "
                  f"block (only the plist and the hook commands differ): rc={rc}")
            if not ok:
                print("     " + text.replace("\n", "\n     "))

        # The base moved after the branch was cut and took the same next version: the head's bump is
        # no longer greater than the base TIP's, even though it is greater than the merge base's.
        g("checkout", "-q", "-B", "plant-base-took-the-version", "base")
        js_edit(); bump(nxt); row(nxt, "artifact")
        g("add", "-A"); g("commit", "-q", "-m", "head")
        g("checkout", "-q", "base")
        wr(SCRIPT, rd(SCRIPT) + "\n// another PR\n"); bump(nxt); row(nxt, "artifact")
        g("add", "-A"); g("commit", "-q", "-m", "another PR landed")
        rc, text = verdict("plant-base-took-the-version")
        ok = rc == 1 and "UNCHANGED" in text
        results.append(ok)
        print(f"{'ok  ' if ok else 'BAD '} plant-base-took-the-version: rc={rc} (want 1)")
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

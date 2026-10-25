#!/usr/bin/env python3
"""install.selftest.py — drive `fleet-reporter/install.sh` end to end against a throwaway HOME (card#7336).

WHAT IT PROVES, each against a control that must come out the other way:
  1. THE GUARD. `install.sh --check-runbook` passes this checkout's runbook and reds each planted divergence
     between the runbook and the script: a block the script runs that the runbook lost, one it holds twice,
     a bash block nobody classified, a `run` anchor the script does not know, and a message the script reads
     that its block no longer prints. The script runs that same check before it runs anything, so a planted
     copy of the checkout is also run whole and must refuse with NOTHING RUN and an untouched HOME.
  2. THE INSTALL, on a HOME that holds a `settings.json` with the user's own hooks and other keys: a run
     with no config installs nothing and names the token command (exit 3); `--set-token` with no terminal
     refuses; `--set-token` at a real pseudo-terminal writes the config; the next run installs, every one of
     the user's hook entries and keys survives, the reporter's hook set is added, the crontab gets the block,
     selftest, the health check and the arrival check each pass against a TLS ingest stub.
  3. A SECOND RUN IS A NO-OP: settings.json, the crontab, the artifact and the config keep their bytes and
     mtimes, no crontab is installed, the flusher is not restarted, and no attempt directory is left.
  4. THE TOKEN NEVER REACHES AN ARGV, AN ENVIRONMENT OR AN OUTPUT: every `execve` of the `--set-token` run
     is recorded with strace (argv and environment), and the token is in none of them, nor in any output of
     any run; the detector is first shown to find a token planted in an argv.
  5. AN UPGRADE: an installed artifact that differs from the checkout's is replaced and the flusher restarted
     on it, and a config for another seat is refused with nothing changed.
  6. A ROTATION: `--set-token` over a config, with the new token in the carried token file, replaces that
     site's token and nothing else, removes the file and restarts the flusher; with the old token revoked
     on the site, the next run is current.

HERMETIC. HOME is a temp directory. `crontab` is a stub on PATH that reads and writes a file under it; the
real crontab is never called except `crontab -l`, read before and after the run, which must be equal. Cron
itself is a thread here that runs the stub crontab's `* * * * *` line every two seconds with the temp HOME,
so the flusher the line starts is the one the arrival check sees. The ingest is a TLS server on 127.0.0.1
with a throwaway self-signed certificate, trusted through `--ca-file`. Every flusher this run starts is
stopped by pid at the end, found by its HOME and artifact path, and the run fails if one outlives that.

Needs: bash, node >= 18, flock, openssl, strace, python3. Exit 0 every check passed, 1 a check failed,
2 a tool it needs is missing (nothing was measured).
"""
from __future__ import annotations

import errno
import hashlib
import http.server
import json
import os
import pty
import re
import secrets
import select
import shlex
import shutil
import signal
import ssl
import subprocess
import sys
import tempfile
import termios
import threading
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
CHECKOUT = HERE.parent
SCRIPT = HERE / "install.sh"
RUNBOOK = HERE / "INSTALL-LINUX.md"
VERSION = re.search(r"^const REPORTER_VERSION = '([^']*)';$", (HERE / "fleet-reporter.js").read_text(), re.M).group(1)

for tool in ("bash", "node", "flock", "openssl", "strace"):
    if not shutil.which(tool):
        print(f"install.selftest: `{tool}` is not on PATH: NOTHING MEASURED", file=sys.stderr)
        sys.exit(2)

fails = 0


def check(what: str, cond: bool, detail: str = "") -> None:
    global fails
    if cond:
        print(f"  ok   {what}")
    else:
        fails += 1
        print(f"  FAIL {what}" + (f"\n       {detail}" if detail else ""), file=sys.stderr)


REAL_CRONTAB = shutil.which("crontab")


def real_crontab_md5() -> str:
    if not REAL_CRONTAB:
        return "no crontab command"
    r = subprocess.run([REAL_CRONTAB, "-l"], capture_output=True)
    return hashlib.md5(r.stdout).hexdigest() + f" rc={r.returncode}"


REAL_BEFORE = real_crontab_md5()
TMP = Path(tempfile.mkdtemp(prefix="fr-install-selftest-"))
HOME = TMP / "home"
STUB = TMP / "stub"
(HOME / ".claude").mkdir(parents=True)
STUB.mkdir()
(STUB / "crontab").write_text(f"""#!/bin/sh
D={STUB}
echo "crontab $*" >> $D/calls.log
case "$1" in
  -l) [ -e $D/installed ] || {{ echo "no crontab for $(id -un)" >&2; exit 1; }}; cat $D/installed ;;
  -r) [ -e $D/installed ] || {{ echo "no crontab for $(id -un)" >&2; exit 1; }}; rm $D/installed ;;
  -) cat > $D/installed ;;
  *) cp "$1" $D/installed ;;
esac
""")
os.chmod(STUB / "crontab", 0o700)
PATH = f"{STUB}:{os.path.dirname(shutil.which('node'))}:/usr/bin:/bin"
ENV = {"HOME": str(HOME), "PATH": PATH, "USER": os.environ.get("USER", "seat"), "LANG": "C.UTF-8"}

USER_SETTINGS = {
    "env": {"SEAT_OWN": "1"},
    "permissions": {"allow": ["Bash(ls:*)"]},
    "statusLine": {"type": "command", "command": "my-status-line"},
    "hooks": {
        "PreToolUse": [{"matcher": "Bash", "hooks": [{"type": "command", "command": "/opt/mine/guard.sh"}]}],
        "SessionStart": [{"matcher": "", "hooks": [{"type": "command", "command": "echo welcome"}]}],
    },
}
SETTINGS = HOME / ".claude" / "settings.json"
SETTINGS.write_text(json.dumps(USER_SETTINGS, indent=4) + "\n")
CFG = HOME / ".config" / "fleet-reporter" / "config.json"
JS = HOME / ".local" / "share" / "fleet-reporter" / "fleet-reporter.js"
SPOOL = HOME / ".local" / "state" / "fleet-reporter"
ATTEMPTS = HOME / ".local" / "state" / "fleet-reporter-install"
TOKEN_FILE = HOME / ".config" / "fleet-reporter" / "mezzanine-reporter-token"
TOKEN = "mzn_" + secrets.token_urlsafe(32)
TOKEN2 = "mzn_" + secrets.token_urlsafe(32)        # the rotation's: carried in the token file
assert re.fullmatch(r"mzn_[A-Za-z0-9_-]{43}", TOKEN) and re.fullmatch(r"mzn_[A-Za-z0-9_-]{43}", TOKEN2)
ACCEPTED = {TOKEN, TOKEN2}
OUTPUTS: list[str] = []      # every byte any run printed, swept for the token at the end


# ── the TLS ingest stub ──────────────────────────────────────────────────────────────────────────
class Ingest:
    def __init__(self) -> None:
        self.posts: list[str] = []
        self.gets: list[str] = []
        self.key, self.crt = TMP / "stub.key", TMP / "stub.crt"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(self.key),
                        "-out", str(self.crt), "-days", "2", "-subj", "/CN=127.0.0.1",
                        "-addext", "subjectAltName=IP:127.0.0.1"], check=True, capture_output=True)
        outer = self

        class H(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *a):
                pass

            def handle(self):
                try:
                    super().handle()
                except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
                    pass

            def answer(self, status: int, body: dict) -> None:
                raw = json.dumps(body).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

            def authed(self) -> bool:
                return self.headers.get("Authorization", "").removeprefix("Bearer ") in ACCEPTED

            def do_GET(self):
                outer.gets.append(self.path)
                if not self.authed():
                    return self.answer(401, {"error": "unauthenticated"})
                self.answer(200, {"accepted_schema_versions": [1], "server_time": "2026-10-25T00:00:00.000Z",
                                  "min_reporter_version": "0.1.0", "current_reporter_version": VERSION})

            def do_POST(self):
                n = int(self.headers.get("Content-Length", 0))
                raw = self.rfile.read(n) if n else b""
                if self.headers.get("Content-Encoding") == "gzip":
                    import gzip
                    raw = gzip.decompress(raw)
                outer.posts.append(self.path)
                if not self.authed():
                    return self.answer(401, {"error": "unauthenticated"})
                try:
                    batch = json.loads(raw)
                except ValueError:
                    batch = {}
                self.answer(202, {"batch_id": batch.get("batch_id"), "accepted": len(batch.get("events", [])),
                                  "duplicates": 0, "ignored_unknown_kinds": 0, "coerced_enum_values": 0,
                                  "server_time": "2026-10-25T00:00:00.000Z"})

        self.httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), H)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(str(self.crt), str(self.key))
        self.httpd.socket = ctx.wrap_socket(self.httpd.socket, server_side=True)
        self.site = f"https://127.0.0.1:{self.httpd.server_address[1]}"
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()


INGEST = Ingest()
VALUES = ["--site", INGEST.site, "--install-id", "selftest-install", "--seat-id", "selftest-seat",
          "--ca-file", str(INGEST.crt)]


def sh(*args: str, script: Path = SCRIPT, env: "dict | None" = None, **kw) -> subprocess.CompletedProcess:
    r = subprocess.run(["bash", str(script), *args], capture_output=True, text=True, env=env or ENV,
                       stdin=subprocess.DEVNULL, start_new_session=True, timeout=420, **kw)
    OUTPUTS.append(r.stdout + r.stderr)
    return r


def snapshot() -> dict:
    """Bytes and mtime of every file the install owns or touches, and the stub crontab."""
    out = {}
    for p in [SETTINGS, CFG, JS, STUB / "installed", *sorted((JS.parent / "fixtures" / "hooks").glob("*.json"))]:
        out[str(p)] = (p.read_bytes(), p.stat().st_mtime_ns) if p.exists() else None
    return out


def crontab_calls() -> list[str]:
    f = STUB / "calls.log"
    return f.read_text().splitlines() if f.exists() else []


def lock_pid() -> "int | None":
    try:
        return json.loads((SPOOL / "flusher.lock").read_text())["pid"]
    except (OSError, ValueError, KeyError):
        return None


# ── cron, as a thread: the stub crontab's every-minute line, every two seconds ───────────────────
CRON_PROCS: list[subprocess.Popen] = []
cron_stop = threading.Event()


def cron() -> None:
    while not cron_stop.wait(2):
        f = STUB / "installed"
        lines = [ln[len("* * * * * "):] for ln in (f.read_text().splitlines() if f.exists() else [])
                 if ln.startswith("* * * * * ")]
        for ln in lines:
            CRON_PROCS.append(subprocess.Popen(["/bin/sh", "-c", ln], env=ENV, stdin=subprocess.DEVNULL,
                                               start_new_session=True))


def our_flushers() -> list[int]:
    """Live processes running THIS run's installed artifact under THIS run's HOME: the only ones signalled."""
    found = []
    for e in Path("/proc").iterdir():
        if not e.name.isdigit():
            continue
        try:
            argv = (e / "cmdline").read_bytes().split(b"\0")
            env = (e / "environ").read_bytes().split(b"\0")
        except OSError:
            continue
        if str(JS).encode() in argv and b"HOME=" + str(HOME).encode() in env:
            found.append(int(e.name))
    return found


def reap() -> None:
    cron_stop.set()
    for pid in our_flushers():
        try:
            os.kill(pid, signal.SIGTERM)
        except OSError:
            pass
    t0 = time.time()
    while our_flushers() and time.time() - t0 < 20:
        time.sleep(0.5)
    for p in CRON_PROCS:
        if p.poll() is None:
            p.terminate()
        p.wait()


# ── 1. the guard ─────────────────────────────────────────────────────────────────────────────────
print("== 1. install.sh --check-runbook holds the runbook and the script to each other ==")
DOC = RUNBOOK.read_text()
r = sh("--check-runbook")
check("this checkout's runbook matches the script", r.returncode == 0 and "matches install.sh" in r.stdout, r.stdout + r.stderr)


def planted(name: str, text: str) -> subprocess.CompletedProcess:
    p = TMP / f"runbook-{name}.md"
    p.write_text(text)
    return sh("--check-runbook", str(p))


PLANTS = {
    "a block the script runs is gone": (DOC.replace("<!-- install.sh: run cron-write -->\n", ""), "block cron-write"),
    "a block the script runs is there twice": (DOC + "\n<!-- install.sh: run cron-write -->\n```bash\ntrue\n```\n",
                                               "2 `run cron-write` anchors"),
    "a new bash block nobody classified": (DOC + "\n```bash\ntrue\n```\n", "no install.sh anchor"),
    "a run anchor the script does not know": (DOC + "\n<!-- install.sh: run step-9 -->\n```bash\ntrue\n```\n",
                                              "block step-9"),
    "a message the script reads, reworded in its block": (DOC.replace('echo "crontab already current: nothing written',
                                                                      'echo "crontab unchanged: nothing written'),
                                                          "[crontab already current]"),
    "an anchor with no block under it": (DOC.replace("<!-- install.sh: run arrival -->\n```bash",
                                                     "<!-- install.sh: run arrival -->\n\n```bash"),
                                         "not followed by a ```bash block"),
}
for what, (text, finding) in PLANTS.items():
    check(f"RED: {what}", text != DOC, "the plant changed nothing, so its red would be vacuous")
    r = planted(re.sub(r"\W+", "-", what), text)
    check(f"  … and --check-runbook reds it, naming it", r.returncode == 1 and finding in r.stderr, r.stdout + r.stderr)

# The same check runs before anything else: a checkout whose runbook diverges installs nothing.
co = TMP / "diverged" / "fleet-reporter"
shutil.copytree(HERE, co, ignore=shutil.ignore_patterns("*.selftest.py"))
(co / "INSTALL-LINUX.md").write_text(PLANTS["a block the script runs is gone"][0])
before = snapshot()
r = sh(*VALUES, script=co / "install.sh")
check("RED: install.sh in a checkout whose runbook lost a block refuses, NOTHING RUN, and changes nothing",
      r.returncode == 1 and "NOTHING RUN" in r.stderr and snapshot() == before and not ATTEMPTS.exists(),
      r.stdout + r.stderr)
shutil.rmtree(TMP / "diverged")

# The commands the script prints for the user to copy are shell-quoted: a URL holding `&`, `;` or `$(…)`
# comes back as one word, unexpanded (review F2). Run in a HOME of its own, with no config.
home2 = TMP / "home-quoting"
(home2 / ".claude").mkdir(parents=True)
odd = "https://example.test/x?a=1&b=2;c$(id)"
r = sh("--site", odd, "--seat-id", "quoted-seat", "--install-id", "quoted-install", env={**ENV, "HOME": str(home2)})
cmd = next((ln[ln.index("bash "):] for ln in r.stdout.splitlines() if "install.sh --set-token" in ln and "bash " in ln), "")
# bash parses the printed line as the user's shell would: its program is swapped for one that prints its words.
head = "bash " + shlex.quote(str(SCRIPT)) + " "
words = subprocess.run(["bash", "-c", "args() { printf '%s\\n' \"$@\"; }; args " + cmd[len(head):]],
                       capture_output=True, text=True, env=ENV).stdout.splitlines() if cmd.startswith(head) else []
check("the printed --set-token command, for a site URL holding & ; and $(…), parses in bash back to that URL as "
      "one word", r.returncode == 3 and "--site" in words and words[words.index("--site") + 1] == odd,
      cmd + "\n" + str(words))
shutil.rmtree(home2)

# ── 2. the install ───────────────────────────────────────────────────────────────────────────────
print("== 2. a fresh install on a HOME with the user's own hooks ==")
before = snapshot()
r = sh(*VALUES)
check("with no config, install.sh installs nothing, names the --set-token command, and exits 3",
      r.returncode == 3 and "--set-token" in r.stdout and "/admin/tokens" in r.stdout and snapshot() == before
      and not ATTEMPTS.exists() and crontab_calls() == [], r.stdout + r.stderr)

r = sh("--set-token", *VALUES)
check("--set-token with no terminal refuses, says to run it in a terminal, and writes nothing",
      r.returncode == 1 and "no terminal" in r.stderr and not CFG.exists() and not TOKEN_FILE.exists()
      and not ATTEMPTS.exists(), r.stdout + r.stderr)


echo_off = False


def strace_run(argv: list[str], log: Path, answer: "bytes | None") -> tuple[int, bytes]:
    """Run argv under strace at a pseudo-terminal, typing `answer` at the token prompt."""
    pid, fd = pty.fork()
    if pid == 0:
        os.chdir(str(TMP))
        os.execve(shutil.which("strace"), ["strace", "-f", "-qq", "-v", "-s", "65536", "-e", "trace=execve",
                                           "-o", str(log), *argv], ENV)
    global echo_off
    out, typed = b"", answer is None
    while True:
        try:
            ready, _, _ = select.select([fd], [], [], 60)
            if not ready:
                break
            chunk = os.read(fd, 4096)
        except OSError as e:
            if e.errno == errno.EIO:
                break
            raise
        if not chunk:
            break
        out += chunk
        if not typed and b"Paste the token" in out:
            # Typed once the prompt's `read -s` has turned echo off, as a person pasting at the prompt does:
            # characters typed AHEAD of the prompt are echoed by the terminal before `read` runs, as on any tty.
            t0 = time.time()
            while termios.tcgetattr(fd)[3] & termios.ECHO and time.time() - t0 < 10:
                time.sleep(0.01)
            echo_off = not termios.tcgetattr(fd)[3] & termios.ECHO
            os.write(fd, answer)
            typed = True
    _, status = os.waitpid(pid, 0)
    os.close(fd)
    OUTPUTS.append(out.decode("utf-8", "replace"))
    return os.waitstatus_to_exitcode(status), out


# The detector is shown to find a token in an argv before its silence is trusted.
ctl_log = TMP / "strace-control.log"
strace_run(["/bin/echo", TOKEN], ctl_log, None)
check("RED: the strace sweep finds a token passed in an argv", TOKEN in ctl_log.read_text())
OUTPUTS.pop()     # the control printed the token on purpose; it is not an install output

log = TMP / "strace-set-token.log"
rc, out = strace_run(["bash", str(SCRIPT), "--set-token", *VALUES], log, (TOKEN + "\n").encode())
trace = log.read_text()
cfg = json.loads(CFG.read_text()) if CFG.exists() else {}
check("--set-token at a terminal writes the config, mode 600, with the token, and removes the token file",
      rc == 0 and CFG.exists() and oct(CFG.stat().st_mode & 0o777) == "0o600"
      and cfg.get("sites", [{}])[0].get("token") == TOKEN and not TOKEN_FILE.exists(),
      out.decode("utf-8", "replace")[-3000:])
check("  … the config carries the given identity, the site's ingest URL and its ca_file, and no agent name",
      (cfg.get("install_id"), cfg.get("seat_id"), cfg.get("sites", [{}])[0].get("ingest_url"),
       cfg.get("sites", [{}])[0].get("ca_file"), cfg.get("protocol_agent_name")) ==
      ("selftest-install", "selftest-seat", INGEST.site + "/api/ingest/events", str(INGEST.crt), None), str(cfg))
check("  … strace recorded the run's execs (the measurement happened): the writer's node and the script's bash",
      'write-config.js"' in trace and "--set-token" in trace)
check("  … and the token is in no argv and no environment of any process the run started", TOKEN not in trace)
check("  … the prompt had turned the terminal's echo off when the token was typed", echo_off)
check("  … and the terminal never showed it", TOKEN.encode() not in out)

# ── cron starts here, as it would once Step 5 has written its line ───────────────────────────────
threading.Thread(target=cron, daemon=True).start()
try:
    r = sh(*VALUES)
    after = json.loads(SETTINGS.read_text())
    check("the install run exits 0 and reports INSTALLED", r.returncode == 0 and "INSTALLED:" in r.stdout,
          (r.stdout + r.stderr)[-4000:])
    check("  … selftest, the health check and the arrival check each passed",
          "selftest rc=0 · health rc=0 · arrival rc=0" in r.stdout, (r.stdout + r.stderr)[-2000:])
    check("  … every key of the user's settings other than hooks is unchanged",
          {k: v for k, v in after.items() if k != "hooks"} == {k: v for k, v in USER_SETTINGS.items() if k != "hooks"})
    check("  … every hook entry the user had is still first in its list, unchanged",
          all(after["hooks"][h][:len(v)] == v for h, v in USER_SETTINGS["hooks"].items()), json.dumps(after["hooks"]))
    hook_set = sorted(p.stem for p in (HERE / "fixtures" / "hooks").glob("*.json"))
    added = sorted(h for h, v in after["hooks"].items()
                   if any(x.get("command") == f"node {JS} hook {h}" for e in v for x in e.get("hooks", [])))
    check("  … and the reporter's entry is added for every hook in the fixture set", added == hook_set, f"{added} != {hook_set}")
    tab = (STUB / "installed").read_text() if (STUB / "installed").exists() else ""
    check("  … the crontab holds one fleet-reporter-flusher block",
          tab.count("# BEGIN fleet-reporter-flusher\n") == 1 and f"{JS} flusher" in tab, tab)
    check("  … the artifact is this checkout's", JS.exists() and JS.read_bytes() == (HERE / "fleet-reporter.js").read_bytes())
    # Every block the script names is RUN, never skipped as empty: each of these lines is printed by the block's
    # own command, so a block that came out of the runbook empty, or was not run, leaves its line missing.
    BLOCK_LINES = {"prereqs": "/flock\n", "backup": "config copied, mode 600", "artifact": "fixtures-match",
                   "resolver": "── INSTALL-LINUX.md block `resolver`", "hooks-scripts": "scripts-saved",
                   "hooks-apply": "\napplied\n", "cron-helpers": "helpers-saved", "cron-write": "installed: ",
                   "config-check": "sites[default].token <well-formed>", "selftest": "selftest rc=0",
                   "health": "server current " + VERSION + ": current", "arrival": "batch(es) accepted: events arrive"}
    check("  … and every block it ran printed its own command's output",
          [b for b, line in BLOCK_LINES.items() if line not in r.stdout] == [],
          str([b for b, line in BLOCK_LINES.items() if line not in r.stdout]))
    check("  … and the ingest stub received batches carrying the token in the header", INGEST.posts != [])

    # ── 3. the second run ────────────────────────────────────────────────────────────────────────
    print("== 3. a second run is a no-op ==")
    before, calls, pid, n_attempts = snapshot(), len(crontab_calls()), lock_pid(), len(list(ATTEMPTS.iterdir()))
    r = sh(*VALUES)
    check("the second run exits 0 and reports ALREADY CURRENT", r.returncode == 0 and "ALREADY CURRENT" in r.stdout,
          (r.stdout + r.stderr)[-3000:])
    check("  … settings.json, the crontab, the artifact, its fixtures and the config keep their bytes and mtimes",
          snapshot() == before)
    check("  … it only read the crontab", all(c == "crontab -l" for c in crontab_calls()[calls:]), str(crontab_calls()[calls:]))
    check("  … the flusher was not restarted", pid is not None and lock_pid() == pid and pid in our_flushers(), f"{pid} -> {lock_pid()}")
    check("  … and it left no attempt directory behind", len(list(ATTEMPTS.iterdir())) == n_attempts)
    r = sh(*VALUES[:4], "--seat-id", "another-seat")
    check("a run naming another seat than the config's refuses, NOTHING RUN, and changes nothing",
          r.returncode == 1 and "NOTHING RUN" in r.stderr and snapshot() == before, r.stdout + r.stderr)

    # ── 5. an upgrade ────────────────────────────────────────────────────────────────────────────
    print("== 5. an upgrade replaces the artifact and restarts the flusher on it (U6 waits 70 s) ==")
    JS.write_text(JS.read_text() + "\n// an older build\n")
    pid = lock_pid()
    r = sh(*VALUES)
    check("an install whose artifact differs from the checkout's replaces it, restarts the flusher, and exits 0",
          r.returncode == 0 and "INSTALLED:" in r.stdout and JS.read_bytes() == (HERE / "fleet-reporter.js").read_bytes()
          and "PASS: the lock holder runs the artifact installed at" in r.stdout and lock_pid() not in (None, pid),
          (r.stdout + r.stderr)[-4000:])

    # ── 5b. an upgrade that dies after Step 1 is finished by the next run (review F1) ────────────
    print("== 5b. an upgrade that fails after installing the artifact is finished by the next run ==")
    good = SETTINGS.read_text()
    JS.write_text(JS.read_text() + "\n// an older build\n")
    pid = lock_pid()
    SETTINGS.write_text("{ not json\n")         # Step 4's merge fails on it, after Step 1 has installed
    r1 = sh(*VALUES)
    SETTINGS.write_text(good)
    check("precondition: the first run installed the new artifact, then failed before restarting the flusher",
          r1.returncode == 1 and JS.read_bytes() == (HERE / "fleet-reporter.js").read_bytes() and lock_pid() == pid,
          (r1.stdout + r1.stderr)[-3000:])
    r2 = sh(*VALUES)
    check("  … and the rerun, with the cause fixed, restarts the flusher on the installed build and exits 0",
          r2.returncode == 0 and "PASS: the lock holder runs the artifact installed at" in r2.stdout
          and lock_pid() not in (None, pid), (r2.stdout + r2.stderr)[-4000:])

    # ── 6. a rotation, from a carried token file ─────────────────────────────────────────────────
    print("== 6. --set-token over a config replaces the token, from a carried file, and restarts the flusher ==")
    TOKEN_FILE.write_text(TOKEN2 + "\n")
    os.chmod(TOKEN_FILE, 0o600)
    before_cfg, pid = json.loads(CFG.read_text()), lock_pid()
    r = sh("--set-token")
    cfg = json.loads(CFG.read_text())
    check("--set-token with a token file in place, and no terminal, uses the file: the site's token is replaced",
          r.returncode == 0 and "token replaced: default" in r.stdout and cfg["sites"][0]["token"] == TOKEN2,
          (r.stdout + r.stderr)[-3000:])
    check("  … every other key of the config is unchanged, the token file is gone, and the flusher was restarted",
          {**cfg, "sites": [{**cfg["sites"][0], "token": None}]}
          == {**before_cfg, "sites": [{**before_cfg["sites"][0], "token": None}]}
          and not TOKEN_FILE.exists() and "PASS: the lock holder runs the artifact installed at" in r.stdout
          and lock_pid() not in (None, pid), r.stdout[-2000:])
    ACCEPTED.discard(TOKEN)       # the old token is revoked on the site, as the rotation's order says
    r = sh(*VALUES)
    check("  … and with the old token revoked, the next run is current on the new one", r.returncode == 0
          and "ALREADY CURRENT" in r.stdout and "health rc=0" in r.stdout, (r.stdout + r.stderr)[-3000:])
finally:
    reap()

# ── 4. the token, in every output ────────────────────────────────────────────────────────────────
print("== 4. the token in the outputs, and what this run leaves behind ==")
check("no output of any run printed either token", not any(t in o for o in OUTPUTS for t in (TOKEN, TOKEN2)))
check("every flusher this run started is stopped", our_flushers() == [], str(our_flushers()))
check("the real crontab is what it was before the run (read with `crontab -l` only)", real_crontab_md5() == REAL_BEFORE)
shutil.rmtree(TMP, ignore_errors=True)
if fails:
    print(f"\ninstall.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print("\ninstall.selftest: all checks passed")

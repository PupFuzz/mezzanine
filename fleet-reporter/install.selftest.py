#!/usr/bin/env python3
"""install.selftest.py — drive `fleet-reporter/install.js` end to end in throwaway homes, on Linux, macOS and
Windows (card#12571; D6 = docs/design/REPORTER-INSTALL.md, whose § 7 names every RED below by its id).

WHAT IT PROVES. Each check carries the D6 § 7 id it implements, and each RED is a property a planted defect
turns red: the suite runs against `--checkout <dir>` (a copy of this directory with a defect planted), and
`--only <ids>` narrows it to the sections that id needs, which is how every RED was first seen to fail. Some
REDs plant their own defect in a copy and need no outside plant; those say so where they run.

  R-all-1   `--paths` equals D1 § 2.1 / § 3.1's rows for this OS, read out of D1 here.
  R-all-2   a token-shaped argument, and every malformed token file, refused by name, nothing written, nothing
            printed of the content; a v2 file disagreeing with a flag; a v1 file with no --site/--seat-id.
  R-all-3   a symlink, a directory and a file another account owns, refused before any read.
  R-all-4   the take rule: no warning for a file nobody else could reach; a warning naming the directory, the
            window from birthtime/ctime (an old mtime does not hide a new copy) and the principals for a
            group-readable file; a file already at the named path is not moved; the file is gone afterwards.
  R-all-5   (Linux) the token in no argv or environment of any process the install started, by strace.
  R-all-6   a second run on a current seat changes nothing and leaves no attempt directory.
  R-all-7   an upgrade in place from the layout `install.sh` left (Linux) and card#12335's hand-run layout
            (Windows): artifact first, the stop second, the holder check PASS.
  R-all-8   the user's hooks and keys survive; the to-do tools key and its marker; the board declaration.
  R-all-9   rotation: no matching site, a --site-name naming another, a case-only URL difference, identity.
  R-all-10  uninstall: the reporter's entries by the parsed comparison, the user's kept, the config before the
            stop, the crontab block / plist, the secrets out of every attempt directory, the prefixes.
  R-all-12  AGENT-INSTALL.md holds the mzn_ rule and the timeout sentence verbatim.
  R-all-15  (Linux, macOS) install.sh refuses --set-token naming --token-file and runs install.js otherwise.
  R-all-16  the atomic write: a symlinked settings.json stays one, its mode survives, a rename that fails leaves
            the target untouched; (Windows) a planted EBUSY is retried.
  R-all-17  a config the CHECKOUT's reporter refuses is never written.
  R-all-18  add-site, remove-site, statusline, set-descriptors: refusals and success.
  R-all-21  (Linux) --render-shipping spawns nothing and writes nothing; another flag beside it refuses.
  R-all-22  issue-file: the stdin route, its refusals, and (Linux, macOS) --prompt at a pseudo-terminal.
  R-lin-1   the crontab block byte-identical to the runbook's at 188e5fce for the same inputs; cron's start.
  R-win-1..4, R-mac-1..4: the per-OS supervision, ACLs, quoting, roaming refusal, launchd states.

  R-all-13 (the crontab read predicate) is `fleet-reporter.selftest.py` § 24's, beside card#12334's history;
  R-all-11, -14, -19 and -20 are `bin/reporter-version-guard.py --selftest`'s; R-srv-1 is the server's.

HERMETIC. Every home is a temp directory. On Linux `crontab` is a stub on PATH and cron is a thread that runs
the stub's `* * * * *` lines through a copy of /bin/sh named `cron`; on macOS `launchctl` is a stub that
starts the plist's program. The real crontab is read with `crontab -l` before and after, and must be equal.
The ingest and the board are TLS stubs on 127.0.0.1 with throwaway certificates. Every flusher a run starts
is stopped at the end, found by its artifact path, and the run fails if one outlives that.

Needs: node >= 18, openssl, python3; Linux also flock and strace. Exit 0 every check passed, 1 a check failed,
2 a tool it needs is missing (nothing was measured).
"""
from __future__ import annotations

import argparse
import hashlib
import http.server
import json
import os
import platform as _platform
import re
import secrets
import shutil
import signal
import ssl
import stat
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
# Every file this suite reads or writes is UTF-8 (the reporter's source included); on Windows Python's default
# codec is the ANSI code page, which cannot decode it (windows-latest, card#12571), so the suite runs in UTF-8 mode.
if sys.platform == "win32" and not sys.flags.utf8_mode:
    sys.exit(subprocess.run([sys.executable, "-X", "utf8", *sys.argv]).returncode)
AP = argparse.ArgumentParser()
AP.add_argument("--checkout", help="the fleet-reporter directory to test (a planted copy); default: this one")
AP.add_argument("--only", help="comma-separated D6 ids; the sections that need them run, and nothing else")
ARGS = AP.parse_args()
CO = Path(ARGS.checkout).resolve() if ARGS.checkout else HERE
INSTALL = CO / "install.js"
ONLY = set(ARGS.only.split(",")) if ARGS.only else None
IS_WIN, IS_MAC = sys.platform == "win32", sys.platform == "darwin"
IS_LIN = not IS_WIN and not IS_MAC
OS = "win" if IS_WIN else "mac" if IS_MAC else "lin"
VERSION = re.search(r"^const REPORTER_VERSION = '([^']*)';$", (CO / "fleet-reporter.js").read_text(encoding="utf-8"), re.M).group(1)
NODE = shutil.which("node")

for tool in ["node", "openssl"] + (["flock", "strace"] if IS_LIN else []):
    if not shutil.which(tool):
        print(f"install.selftest: `{tool}` is not on PATH: NOTHING MEASURED", file=sys.stderr)
        sys.exit(2)


def want(*ids: str) -> bool:
    return ONLY is None or bool(ONLY & set(ids))


fails = 0
seen: dict = {}


def check(rid: str, what: str, cond: bool, detail: str = "") -> None:
    global fails
    seen.setdefault(rid, []).append(bool(cond))
    if cond:
        print(f"  ok   {rid}: {what}")
    else:
        fails += 1
        print(f"  FAIL {rid}: {what}" + (f"\n       {detail[-3000:]}" if detail else ""), file=sys.stderr)


def not_measured(rid: str, why: str) -> None:
    print(f"  --   {rid}: NOT MEASURED on {OS}: {why}")


REAL_CRONTAB = None if IS_WIN else shutil.which("crontab")


def real_crontab_md5() -> str:
    if not REAL_CRONTAB:
        return "no crontab command"
    r = subprocess.run([REAL_CRONTAB, "-l"], capture_output=True)
    return hashlib.md5(r.stdout).hexdigest() + f" rc={r.returncode}"


REAL_BEFORE = real_crontab_md5()
# Under /tmp on POSIX, traversable, so R-all-4's group-readable file is reachable as on a real home: macOS's own
# temp directory sits under a 0700 per-user folder, where no other account could reach the file at all.
TMP = Path(tempfile.mkdtemp(prefix="fr-install-selftest-", dir=None if IS_WIN else "/tmp")).resolve()
if not IS_WIN:
    os.chmod(TMP, 0o755)
OUTPUTS: list[str] = []
SECRETS: set = set()
STARTED: set = set()        # flusher pids this run saw, reaped at the end


def token() -> str:
    t = "mzn_" + secrets.token_urlsafe(32)
    assert re.fullmatch(r"mzn_[A-Za-z0-9_-]{43}", t)
    SECRETS.add(t)
    return t


# ── the TLS stubs: ingest sites and the board ────────────────────────────────────────────────────────
BOARD_TOKEN = "kb_" + secrets.token_urlsafe(24)
SECRETS.add(BOARD_TOKEN)
BOARD_ANSWER: list = [200, {"data": {"id": 14}}]


class Ingest:
    def __init__(self, name: str, host: str = "127.0.0.1") -> None:
        self.accepted: set = set()
        self.posts: list[str] = []
        self.key, self.crt = TMP / f"{name}.key", TMP / f"{name}.crt"
        subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(self.key), "-out", str(self.crt),
                        "-days", "2", "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1,DNS:localhost"],
                       check=True, capture_output=True)
        outer = self

        class H(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *a):
                pass

            def handle(self):
                try:
                    super().handle()
                except (BrokenPipeError, ConnectionResetError, ssl.SSLError, OSError):
                    pass

            def answer(self, status: int, body: dict) -> None:
                raw = json.dumps(body).encode()
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(raw)))
                self.end_headers()
                self.wfile.write(raw)

            def authed(self) -> bool:
                return self.headers.get("Authorization", "").removeprefix("Bearer ") in outer.accepted

            def do_GET(self):
                if self.path == "/api/v3/users/current.json":
                    if self.headers.get("Authorization", "") != "Bearer " + BOARD_TOKEN:
                        return self.answer(401, {"error": "unauthenticated"})
                    return self.answer(BOARD_ANSWER[0], BOARD_ANSWER[1])
                if not self.authed():
                    return self.answer(401, {"error": "unauthenticated"})
                self.answer(200, {"accepted_schema_versions": [1], "server_time": "2026-10-29T00:00:00.000Z",
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
                self.answer(202, {"batch_id": batch.get("batch_id"), "accepted": len(batch.get("events", [])), "duplicates": 0,
                                  "ignored_unknown_kinds": 0, "coerced_enum_values": 0, "server_time": "2026-10-29T00:00:00.000Z"})

        self.httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), H)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(str(self.crt), str(self.key))
        self.httpd.socket = ctx.wrap_socket(self.httpd.socket, server_side=True)
        self.port = self.httpd.server_address[1]
        self.site = f"https://{host}:{self.port}"
        self.url = self.site + "/api/ingest/events"
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()


INGEST = Ingest("site-a")
INGEST2 = Ingest("site-b", host="localhost")
INGEST3 = Ingest("site-c")


# ── a seat: a throwaway home, its stubs, and the runs ────────────────────────────────────────────────
CRON_SEATS: list = []
CRON_PROCS: list = []
cron_stop = threading.Event()


class Seat:
    def __init__(self, name: str, home_name: str = "home", coord: bool = True, checkout: Path = CO) -> None:
        self.root = TMP / name
        self.home = self.root / home_name
        self.stub = self.root / "stub"
        self.install = checkout / "install.js"
        (self.home / ".claude").mkdir(parents=True)
        self.stub.mkdir()
        node_dir = os.path.dirname(NODE)
        if IS_WIN:
            local, roaming = self.home / "AppData" / "Local", self.home / "AppData" / "Roaming"
            local.mkdir(parents=True)
            roaming.mkdir(parents=True)
            # The runner's OWN environment, as a real seat's Git Bash carries the user's, with the profile folders
            # moved to the throwaway home and the reporter's and the coordination framework's variables taken out.
            # A hand-picked minimal set (no ProgramFiles, ALLUSERSPROFILE, …) is an environment no seat has, and
            # PowerShell locates its own modules from those variables.
            self.env = {k: v for k, v in os.environ.items() if not k.upper().startswith(("FLEET_REPORTER", "COORD_"))}
            self.env.update({"USERPROFILE": str(self.home), "HOME": str(self.home), "APPDATA": str(roaming), "LOCALAPPDATA": str(local),
                             "PATH": os.pathsep.join([str(self.stub), node_dir, os.environ.get("PATH", "")])})
        else:
            self.env = {"HOME": str(self.home), "PATH": f"{self.stub}:{node_dir}:/usr/bin:/bin", "USER": os.environ.get("USER", "seat"),
                        "LOGNAME": os.environ.get("USER", "seat"), "LANG": "C.UTF-8"}
        self.env["NODE_EXTRA_CA_CERTS"] = str(INGEST.crt)
        if IS_WIN:
            self.env["FLEET_REPORTER_INSTALL_TRACE"] = "1"      # a stall there names its call (install.js's trace)
        if IS_LIN:
            (self.stub / "crontab").write_text(f"""#!/bin/sh
D={self.stub}
echo "crontab $*" >> $D/calls.log
if [ -e $D/broken ] && [ "$1" = -l ]; then echo "crontab: cannot open the spool: Permission denied" >&2; exit 2; fi
case "$1" in
  -l) [ -e $D/installed ] || {{ echo "no crontab for $(id -un)" >&2; exit 1; }}; cat $D/installed ;;
  -r) [ -e $D/installed ] || {{ echo "no crontab for $(id -un)" >&2; exit 1; }}; rm $D/installed ;;
  *) cp "$1" $D/installed ;;
esac
""")
            os.chmod(self.stub / "crontab", 0o700)
            shutil.copy("/bin/sh", self.stub / "cron")      # cron's process name, for the ancestry check
        if IS_MAC:
            (self.stub / "launchctl").write_text(LAUNCHCTL_STUB.replace("@STUB@", str(self.stub)).replace("#!/usr/bin/env python3", "#!" + sys.executable, 1))
            os.chmod(self.stub / "launchctl", 0o700)
        if coord:
            cc = self.home / ".config" / "coord"
            cc.mkdir(parents=True)
            (cc / "kanban-token").write_text(BOARD_TOKEN + "\n")
            (cc / "credentials.ini").write_text(f"[kanban]\napi_token_file = {cc / 'kanban-token'}\n")
            (cc / "coordination.config.json").write_text(json.dumps({"kanban": {"base_url": INGEST.site + "/"}, "roster": [{"name": "seat-agent"}]}))
            (self.home / ".claude" / "settings.local.json").write_text(json.dumps({"env": {"COORD_CONFIG": str(cc / "coordination.config.json")}}))
        self.T = json.loads(self.node("--paths").stdout)
        CRON_SEATS.append(self)

    def node(self, *args: str, stdin: "str | None" = None, timeout: int = 420, env: "dict | None" = None, install: "Path | None" = None):
        return run_to_files([NODE, str(install or self.install), *args], env or self.env, self.root, stdin=stdin, timeout=timeout)

    def settings(self) -> dict:
        return json.loads(Path(self.T["settings"]).read_text())

    def config(self) -> dict:
        return json.loads(Path(self.T["config"]).read_text())

    def lock_pid(self) -> "int | None":
        try:
            return json.loads((Path(self.T["spool"]) / "flusher.lock").read_text())["pid"]
        except (OSError, ValueError, KeyError):
            return None

    def crontab(self) -> str:
        f = self.stub / "installed"
        return f.read_text() if f.exists() else ""

    def calls(self) -> list:
        f = self.stub / "calls.log"
        return f.read_text().splitlines() if f.exists() else []

    def attempts(self) -> list:
        d = Path(self.T["attempts"])
        return sorted(d.iterdir()) if d.exists() else []

    def wait_flusher(self, timeout: float = 40) -> "int | None":
        t0 = time.time()
        while time.time() - t0 < timeout:
            p = self.lock_pid()
            if p and pid_alive(p):
                STARTED.add(p)
                return p
            time.sleep(0.5)
        return None

    def snapshot(self) -> dict:
        out = {}
        paths = [Path(self.T[k]) for k in ("settings", "config", "js")] + [self.stub / "installed"]
        if self.T.get("plist"):
            paths.append(Path(self.T["plist"]))
        fx = Path(self.T["fixtures"])
        paths += sorted(fx.glob("*.json")) if fx.exists() else []
        for p in paths:
            out[str(p)] = (p.read_bytes(), p.stat().st_mtime_ns) if p.exists() else None
        return out


LAUNCHCTL_STUB = r'''#!/usr/bin/env python3
import os, plistlib, signal, subprocess, sys
D = "@STUB@"
args = sys.argv[1:]
uid = os.getuid()
svc = f"gui/{uid}/com.pupfuzz.fleet-reporter"
open(os.path.join(D, "launchctl.log"), "a").write("launchctl " + " ".join(args) + "\n")
def rd(n):
    try: return open(os.path.join(D, n)).read().strip()
    except OSError: return ""
def alive(p):
    try: os.kill(int(p), 0); return True
    except (OSError, ValueError): return False
def start():
    pl = plistlib.load(open(rd("loaded"), "rb"))
    env = dict(os.environ); env.update(pl.get("EnvironmentVariables", {}))
    p = subprocess.Popen(pl["ProgramArguments"], env=env, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    open(os.path.join(D, "pid"), "w").write(str(p.pid))
def stop():
    p = rd("pid")
    if alive(p):
        os.kill(int(p), signal.SIGTERM)
if args[:1] == ["print"]:
    if os.path.exists(os.path.join(D, "no-gui")): sys.exit(113)
    if args[1] == f"gui/{uid}": print(f"gui/{uid} = {{"); sys.exit(0)
    if args[1] != svc or not rd("loaded"): print("Could not find service", file=sys.stderr); sys.exit(113)
    print(svc + " = {"); print("\tpath = " + rd("loaded"))
    if alive(rd("pid")): print("\tpid = " + rd("pid"))
    print("}"); sys.exit(0)
if args[:1] == ["bootstrap"]:
    open(os.path.join(D, "loaded"), "w").write(args[2]); start(); sys.exit(0)
if args[:1] == ["bootout"]:
    if not rd("loaded"): sys.exit(3)
    stop(); os.unlink(os.path.join(D, "loaded")); sys.exit(0)
if args[:2] == ["kickstart", "-k"]:
    open(os.path.join(D, "launchctl.log"), "a").write("kickstart: the previous pid was " + ("ALIVE" if alive(rd("pid")) else "already stopped") + "\n")
    stop(); start(); sys.exit(0)
sys.exit(64)
'''


class Ran:
    def __init__(self, returncode: int, stdout: str, stderr: str) -> None:
        self.returncode, self.stdout, self.stderr = returncode, stdout, stderr


_ran = [0]


def run_to_files(argv: list, env: dict, cwd: Path, stdin: "str | None" = None, timeout: int = 420) -> Ran:
    """Run the installer AS AGENT-INSTALL.md HAS IT RUN: output to a file, never a pipe the caller drains. On
    Windows the flusher the installer starts inherits the installer's inheritable handles, so a caller reading
    the installer's output through a pipe would wait for that flusher to exit (windows-latest, card#12571)."""
    _ran[0] += 1
    base = TMP / f"run-{_ran[0]}"
    t0, note = time.time(), ""
    with open(f"{base}.out", "wb") as o, open(f"{base}.err", "wb") as e:
        try:
            if stdin is None:
                rc = subprocess.run(argv, stdout=o, stderr=e, stdin=subprocess.DEVNULL, env=env, timeout=timeout, cwd=str(cwd)).returncode
            else:
                rc = subprocess.run(argv, stdout=o, stderr=e, input=stdin.encode(), env=env, timeout=timeout, cwd=str(cwd)).returncode
        except subprocess.TimeoutExpired:
            # A run past its bound is a FAILURE of the check that made it, with what it printed shown — never a
            # crash that hides every later section.
            rc, note = 124, f"\n[the run was killed after {timeout} s, still running]"
    r = Ran(rc, Path(f"{base}.out").read_text(encoding="utf-8", errors="replace"),
            Path(f"{base}.err").read_text(encoding="utf-8", errors="replace") + note)
    if note:
        print(f"  !!   a run timed out after {round(time.time() - t0)} s: {' '.join(str(a) for a in argv[1:3])} …; what it printed:\n"
              + "\n".join("       " + ln for ln in (r.stdout + r.stderr).splitlines()[-60:]), file=sys.stderr)
    OUTPUTS.append(r.stdout + r.stderr)
    return r


def run_on_pipes(argv: list, env: dict, cwd: Path, timeout: int = 600) -> "tuple[Ran, bool]":
    """Run the installer with its output on PIPES, as Claude's Bash tool runs a command, and report whether both
    pipes reached EOF within 30 s of the installer's exit. A child it started that holds a pipe open (the Windows
    flusher start inherits the installer's handles) keeps that pipe from ever ending, and a caller reading it
    hangs. The pipes are read on threads, never by `communicate()`, whose timeout path would itself block."""
    p = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.PIPE, stdin=subprocess.DEVNULL, env=env, cwd=str(cwd))
    bufs = {"out": b"", "err": b""}

    def drain(name, f):
        for chunk in iter(lambda: f.read(4096), b""):
            bufs[name] += chunk
    threads = [threading.Thread(target=drain, args=("out", p.stdout), daemon=True), threading.Thread(target=drain, args=("err", p.stderr), daemon=True)]
    for t in threads:
        t.start()
    try:
        rc = p.wait(timeout=timeout)
    except subprocess.TimeoutExpired:
        p.kill()
        rc = 124
    for t in threads:
        t.join(30)
    eof = not any(t.is_alive() for t in threads)
    r = Ran(rc, bufs["out"].decode("utf-8", "replace"), bufs["err"].decode("utf-8", "replace") + ("" if rc != 124 else f"\n[killed after {timeout} s]"))
    OUTPUTS.append(r.stdout + r.stderr)
    return r, eof


def pid_alive(pid: int) -> bool:
    if IS_WIN:
        r = subprocess.run(["tasklist", "/FI", f"PID eq {pid}", "/NH"], capture_output=True, text=True)
        return str(pid) in r.stdout
    try:
        os.kill(pid, 0)
    except OSError:
        return False
    if IS_LIN:
        try:
            return " Z " not in Path(f"/proc/{pid}/stat").read_text().split(")", 1)[1][:3]
        except OSError:
            return False
    return True


def flushers_of(js_paths: list) -> list:
    """Live processes running one of these artifacts' `flusher`: the only ones this suite signals."""
    found = []
    if IS_WIN:
        ps = ("Get-CimInstance Win32_Process -Filter \"Name='node.exe'\" | ForEach-Object { \"$($_.ProcessId)`t$($_.CommandLine)\" }")
        out = subprocess.run(["powershell", "-NoProfile", "-Command", ps], capture_output=True, text=True).stdout
        rows = [ln.split("\t", 1) for ln in out.splitlines() if "\t" in ln]
    else:
        out = subprocess.run(["ps", "-axo", "pid=,command="], capture_output=True, text=True).stdout
        rows = [ln.strip().split(None, 1) for ln in out.splitlines() if ln.strip()]
    for row in rows:
        if len(row) == 2 and "flusher" in row[1] and any(str(j) in row[1] or str(j).replace("\\", "/") in row[1] for j in js_paths):
            found.append(int(row[0]))
    return found


def cron() -> None:
    while not cron_stop.wait(2):
        for s in list(CRON_SEATS):
            f = s.stub / "installed"
            lines = [ln[len("* * * * * "):] for ln in (f.read_text().splitlines() if f.exists() else []) if ln.startswith("* * * * * ")]
            for ln in lines:
                CRON_PROCS.append(subprocess.Popen([str(s.stub / "cron"), "-c", ln], env=s.env, stdin=subprocess.DEVNULL, start_new_session=True))


def reap() -> None:
    cron_stop.set()
    js = [Path(s.T["js"]) for s in CRON_SEATS]
    for _ in range(3):
        pids = set(flushers_of(js)) | {p for p in STARTED if pid_alive(p)}
        for pid in pids:
            try:
                os.kill(pid, signal.SIGTERM)
            except OSError:
                pass
        t0 = time.time()
        while flushers_of(js) and time.time() - t0 < 20:
            time.sleep(0.5)
    for p in CRON_PROCS:
        if p.poll() is None:
            p.terminate()
        p.wait()


def write_v2(path: Path, tok: str, url: str, install_id: str, seat_id: str, mode: int = 0o600) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(f"token {tok}\ningest_url {url}\ninstall_id {install_id}\nseat_id {seat_id}\n".encode())
    if not IS_WIN:
        os.chmod(path, mode)
    return path


def tail(r) -> str:
    return (r.stdout + r.stderr)[-4000:]


def node_eval(src: str, *args: str, env: "dict | None" = None) -> subprocess.CompletedProcess:
    return subprocess.run([NODE, "-e", src, str(INSTALL), *args], capture_output=True, text=True, env=env)


def planted_copy(name: str, *edits) -> Path:
    """A copy of the checkout with each (file, old, new) edit applied; an edit whose text is absent raises, so
    a plant that matches nothing can never pass as a RED."""
    d = TMP / f"plant-{name}"
    shutil.copytree(CO, d, ignore=shutil.ignore_patterns("*.selftest.py", "__pycache__"))
    for f, old, new in edits:
        p = d / f
        t = p.read_text(encoding="utf-8")
        if old not in t:
            raise AssertionError(f"the plant {name} does not match {f}: {old[:80]!r}")
        p.write_text(t.replace(old, new, 1), encoding="utf-8")
    return d


# R-all-12's two rules, verbatim (whitespace folded): AGENT-INSTALL.md is prose no other guard reads.
AGENT_RULES = [
    "If a reply from the user holds a string that starts `mzn_`, the token has passed through you: run nothing, tell the "
    "user so, and ask them to revoke its prefix on the Seat tokens page and issue another.",
    "Run the installer in the background with its output to a file, and read the file's last lines: a foreground call is "
    "cut off by the Bash tool's timeout before the install ends.",
]

cron_thread = threading.Thread(target=cron, daemon=True)
cron_thread.start()

try:
    # ── unit level: the parser, the matching key, the hook comparison, on every OS ────────────────────
    if want("R-all-2", "R-all-9", "R-win-3", "R-all-18", "units"):
        print("== units: the token-file parser, siteKey, and the parsed hook comparison ==")
        T0 = "mzn_" + "a" * 43
        bad = {
            "two tokens": f"{T0}\n{T0}\n", "empty": "", "a carriage return": f"{T0}\r\n",
            "a fifth key": f"token {T0}\ningest_url https://h.test/api/ingest/events\ninstall_id ab\nseat_id cd\nextra x\n",
            "a repeated key": f"token {T0}\ntoken {T0}\ningest_url https://h.test/api/ingest/events\ninstall_id ab\nseat_id cd\n",
            "a non-https URL": f"token {T0}\ningest_url http://h.test/api/ingest/events\ninstall_id ab\nseat_id cd\n",
            "a bad slug": f"token {T0}\ningest_url https://h.test/api/ingest/events\ninstall_id AB\nseat_id cd\n",
            "trailing whitespace": f"token {T0} \ningest_url https://h.test/api/ingest/events\ninstall_id ab\nseat_id cd\n",
        }
        # BY NAME: each refusal names its own rule, so a rule disabled is a refusal that names another, and reds.
        names = {"two tokens": "line 1 is not one", "empty": "is empty", "a carriage return": "carriage return",
                 "a fifth key": "line 5 carries a key", "a repeated key": "token more than once",
                 "a non-https URL": "ingest_url is not an https://", "a bad slug": "install_id fails",
                 "trailing whitespace": "line 1 is not one"}
        r = node_eval("const m=require(process.argv[1]); const out={}; for (const [k,v] of Object.entries(JSON.parse(process.argv[2]))) { try { m.parseTokenFile(v); out[k]='ACCEPTED'; } catch (e) { out[k]=e.message; } } console.log(JSON.stringify(out));", json.dumps(bad))
        got = json.loads(r.stdout or "{}")
        check("R-all-2", "the parser refuses a two-token, empty, CR-bearing, fifth-key, repeated-key, non-https, bad-slug and "
              "trailing-whitespace file, each naming its own rule, and no message carries the token",
              set(got) == set(bad) and all(names[k] in v and T0 not in v for k, v in got.items()), json.dumps(got, indent=1))
        r = node_eval("const m=require(process.argv[1]); const k=[m.siteKey('https://LOCALHOST:8443/api/ingest/events'), m.siteKey('https://localhost:8443/api/ingest/events/'), m.siteKey('https://localhost:8443/'), m.siteKey('https://localhost:8443/api/ingest/events')]; console.log(JSON.stringify(k));")
        keys = json.loads(r.stdout or "[]")
        check("R-all-9", "siteKey: a URL differing only in host case, in a trailing slash, or a bare origin keys the same site",
              len(keys) == 4 and len(set(keys)) == 1, r.stdout + r.stderr)
        # The parsed comparison on win32, from any OS: the quoted forward-slash form, a backslash variant and an
        # unquoted variant of one artifact all recognised; wiring adds nothing to the first and collapses the rest.
        r = node_eval(r"""
const m=require(process.argv[1]); const js='C:\\Users\\a b\\AppData\\Local\\fleet-reporter\\fleet-reporter.js';
const fwd='node "C:/Users/a b/AppData/Local/fleet-reporter/fleet-reporter.js" hook Stop';
const bs='node "C:\\Users\\A B\\AppData\\Local\\fleet-reporter\\fleet-reporter.js" hook Stop';
const s1={hooks:{Stop:[{matcher:'',hooks:[{type:'command',command:fwd}]}]}};
const w1=m.wireHooks(s1,'win32',js,[{hook:'Stop',matcher:''}]);
const s2={hooks:{Stop:[{matcher:'',hooks:[{type:'command',command:fwd}]},{matcher:'',hooks:[{type:'command',command:bs}]}]}};
const w2=m.wireHooks(s2,'win32',js,[{hook:'Stop',matcher:''}]);
const s3={hooks:{Stop:[{matcher:'',hooks:[{type:'command',command:bs}]}]}};
const w3=m.wireHooks(s3,'win32',js,[{hook:'Stop',matcher:''}]);
const sl='node "C:\\Users\\a b\\AppData\\Local\\fleet-reporter\\fleet-reporter.js" statusline';
console.log(JSON.stringify({cmd:m.hookCommand('win32',js,'hook Stop')===fwd, w1:[w1.added.length,w1.changed.length,JSON.stringify(w1.settings)===JSON.stringify(s1)],
 w2:[w2.added.length, w2.settings.hooks.Stop.length], w3:[w3.added.length, w3.settings.hooks.Stop[0].hooks[0].command===fwd],
 sl: m.parseReporterCommand(sl) && m.samePath('win32', m.parseReporterCommand(sl).path, js), rm: JSON.stringify(m.withoutReporter(s2,'win32',js))}));
""")
        u = json.loads(r.stdout or "{}")
        check("R-win-3", "win32 hook commands: the canonical form is quoted forward-slash; a settings file already in that form is "
              "left untouched; a backslash variant beside it is recognised and removed; one alone is rewritten to the "
              "canonical form; a backslash-form statusline is recognised; uninstall's strip leaves no reporter entry",
              u == {"cmd": True, "w1": [0, 0, True], "w2": [0, 1], "w3": [0, True], "sl": True, "rm": "{}"}, r.stdout + r.stderr)

    # ── R-all-1: the platform table against D1 ────────────────────────────────────────────────────────
    if want("R-all-1"):
        print("== R-all-1: --paths against D1 § 2.1 and § 3.1 ==")
        s = Seat("paths", coord=False)
        d1 = (CO.parent / "docs" / "design" / "EVENT-SCHEMA.md").read_text(encoding="utf-8")
        row = "Windows" if IS_WIN else "Linux / macOS"
        inst = re.search(r"^\| " + re.escape(row) + r" \| `([^`]+)` \|", d1, re.M)
        cfg = re.search(r"^\| " + ("Windows" if IS_WIN else "Linux/macOS") + r" \| `([^`]*config\.json)`", d1, re.M)

        def expand(v: str) -> str:
            v = v.replace("~", str(s.home)).replace("%LOCALAPPDATA%", s.env.get("LOCALAPPDATA", "")).replace("%APPDATA%", s.env.get("APPDATA", ""))
            return os.path.normpath(v.split(",")[0].strip())
        check("R-all-1", f"--paths' artifact directory and config are D1's `{row}` rows, read out of D1",
              bool(inst and cfg) and os.path.normpath(s.T["artifactDir"]) == expand(inst.group(1))
              and os.path.normpath(s.T["config"]) == expand(cfg.group(1)), f"{inst and inst.group(1)} / {cfg and cfg.group(1)} vs {s.T}")

    # ── refusals before anything is written ───────────────────────────────────────────────────────────
    if want("R-all-2", "R-all-3"):
        print("== R-all-2 / R-all-3: refusals, nothing written, nothing printed ==")
        s = Seat("refusals")
        tok = token()
        r = s.node(tok)
        check("R-all-2", "an argument matching the token shape: exit 1, NOTHING RUN, the token not printed back, nothing written",
              r.returncode == 1 and "looks like a seat token" in r.stderr and tok not in r.stdout + r.stderr
              and not Path(s.T["config"]).exists() and not s.attempts(), tail(r))
        r = s.node("--token-file", "x", "--site", "https://h.test/" + tok)
        check("R-all-2", "  … also when the token rides inside another argument", r.returncode == 1 and tok not in r.stdout + r.stderr, tail(r))
        dl = s.home / "Downloads"
        for label, body in {"two tokens": f"{tok}\n{tok}\n", "a carriage return": f"{tok}\r\n",
                            "a fifth key": f"token {tok}\ningest_url {INGEST.url}\ninstall_id ab\nseat_id cd\nx y\n"}.items():
            slug = re.sub(r"\W+", "-", label)
            f = dl / f"bad-{slug}"
            f.parent.mkdir(exist_ok=True)
            f.write_bytes(body.encode())
            r = s.node("--token-file", str(f), "--site", INGEST.site, "--seat-id", "x-seat", "--ca-file", str(INGEST.crt))
            check("R-all-2", f"a token file with {label}: refused by name, NOTHING WRITTEN, treated as exposed, the content never printed",
                  r.returncode == 1 and "NOTHING WRITTEN" in r.stderr and "exposed" in r.stderr and tok not in r.stdout + r.stderr
                  and not Path(s.T["config"]).exists() and f.exists(), tail(r))
        v2 = write_v2(dl / "v2", tok, INGEST.url, "inst-a", "seat-a")
        r = s.node("--token-file", str(v2), "--seat-id", "other-seat", "--ca-file", str(INGEST.crt))
        check("R-all-2", "a v2 file disagreeing with --seat-id: refused, nothing written",
              r.returncode == 1 and "disagrees" in r.stderr and not Path(s.T["config"]).exists() and v2.exists(), tail(r))
        v1 = dl / "v1"
        v1.write_bytes((tok + "\n").encode())      # bytes: text mode writes CRLF on Windows
        r = s.node("--token-file", str(v1))
        check("R-all-2", "a v1 file with no --site and no --seat-id: refused naming both",
              r.returncode == 1 and "--site" in r.stderr and "--seat-id" in r.stderr and not Path(s.T["config"]).exists(), tail(r))
        # R-all-3: before any read.
        link = dl / "link"
        try:
            link.symlink_to(v2)
            r = s.node("--token-file", str(link))
            check("R-all-3", "a symlink is refused before any read", r.returncode == 1 and "symbolic link" in r.stderr and v2.exists(), tail(r))
        except OSError as e:
            not_measured("R-all-3 (symlink)", f"this account cannot make a symlink here ({e})")
        r = s.node("--token-file", str(dl))
        check("R-all-3", "a directory is refused before any read", r.returncode == 1 and "not a regular file" in r.stderr, tail(r))
        foreign = Path(os.environ.get("SystemRoot", r"C:\Windows")) / "System32" / "drivers" / "etc" / "hosts" if IS_WIN else Path("/etc/passwd")
        r = s.node("--token-file", str(foreign), "--site", INGEST.site, "--seat-id", "x-seat")
        check("R-all-3", f"a file another account owns ({foreign}) is refused before any read",
              r.returncode == 1 and "not owned by this account" in r.stderr and "line" not in r.stderr, tail(r))

    # ── the fresh install (seat A), with the take rule's warning and, on Linux, under strace ──────────
    A = None
    A_TOK = token()
    if want("R-all-4", "R-all-5", "R-all-6", "R-all-7", "R-all-8", "R-all-9", "R-all-10", "R-all-16", "R-all-17",
            "R-all-18", "R-lin-1", "R-win-1", "R-win-2", "R-win-3", "R-mac-1", "R-mac-2", "R-mac-3"):
        print("== a fresh install: one sentence's worth — a token file and nothing else ==")
        A = Seat("seat-a", home_name="seat home" if IS_WIN else "home")
        INGEST.accepted.add(A_TOK)
        user = {"env": {"SEAT_OWN": "1"}, "permissions": {"allow": ["Bash(ls:*)"]},
                "statusLine": {"type": "command", "command": "my-status-line"},
                "hooks": {"PreToolUse": [{"matcher": "Bash", "hooks": [{"type": "command", "command": "/opt/mine/guard.sh"}]}],
                          "SessionStart": [{"matcher": "", "hooks": [{"type": "command", "command": "echo welcome"}]}]}}
        Path(A.T["settings"]).write_text(json.dumps(user, indent=4) + "\n")
        dl = A.home / "Downloads"
        dl.mkdir()
        if not IS_WIN:
            os.chmod(A.home, 0o755)
            os.chmod(dl, 0o755)
        tf = write_v2(dl / "mezzanine-reporter-token", A_TOK, INGEST.url, "inst-a", "seat-a", mode=0o640)
        if not IS_WIN:
            os.utime(tf, (946684800, 946684800))           # an old mtime: a copy keeps one, and must not hide the copy's age
        else:
            subprocess.run(["icacls", str(tf), "/grant", "*S-1-1-0:R"], capture_output=True)   # Everyone: read — a foreign ACE
        argv = [NODE, str(A.install), "--token-file", str(tf), "--ca-file", str(INGEST.crt)]
        trace = None
        if IS_LIN:
            log = TMP / "strace-install.log"
            r = subprocess.run(["strace", "-f", "-qq", "-v", "-s", "65536", "-e", "trace=execve", "-o", str(log), *argv],
                               capture_output=True, text=True, env=A.env, timeout=600, cwd=str(A.root))
            OUTPUTS.append(r.stdout + r.stderr)
            trace = log.read_text()
        else:
            # On PIPES, as Claude's Bash tool runs it; on Windows this run's flusher is the installer's own start,
            # still alive when the installer exits.
            ctl, ctl_eof = run_on_pipes([NODE, "-e", "require('child_process').spawn(process.execPath, ['-e', 'setTimeout(() => {}, 45000)'], "
                                         "{ detached: true, stdio: ['ignore', 'inherit', 'ignore'] }).unref()"], A.env, A.root, timeout=60)
            check("R-win-1" if IS_WIN else "R-mac-1", "RED: the pipe check reads a parent whose detached child holds its stdout as "
                  "never ending", ctl.returncode == 0 and not ctl_eof)
            r, eof = run_on_pipes(argv, A.env, A.root, timeout=900)
            check("R-win-1" if IS_WIN else "R-mac-1", "the installer's output pipes end when it exits, though the flusher it "
                  "started runs on: a caller reading them, as Claude's Bash tool does, is not left waiting",
                  eof and r.returncode != 124, tail(r))
        out = r.stdout + r.stderr
        cfg = A.config() if Path(A.T["config"]).exists() else {}
        check("R-all-8", "the install exits 0, INSTALLED, selftest · health · arrival each rc=0",
              r.returncode == 0 and "INSTALLED:" in r.stdout and "selftest rc=0 · health rc=0 · arrival rc=0" in r.stdout, tail(r))
        warn_line = next((ln for ln in out.splitlines() if "token-take: WARNING" in ln), "")
        m = re.search(r"for (\d+) s", warn_line)
        check("R-all-4", "a token file another principal could read is TAKEN with one warning naming its directory, the window "
              "from birthtime/ctime — small, though its mtime is from 2000 — and the principals",
              bool(warn_line) and str(dl) in warn_line and m is not None and int(m.group(1)) < 600
              and ("group:" in warn_line or "foreign ACEs" in warn_line) and "revoke this prefix" in warn_line, warn_line or tail(r))
        tdir = Path(A.T["configDir"])
        check("R-all-4", "  … the file is gone from where it was saved and from the config directory, and the config passed",
              not tf.exists() and not Path(A.T["tokenFile"]).exists() and "token file unlinked; overwrite best-effort" in out
              and cfg.get("sites", [{}])[0].get("token") == A_TOK, tail(r))
        if not IS_WIN:
            check("R-all-4", "  … the config is 0600 in a 0700 directory",
                  oct(Path(A.T["config"]).stat().st_mode & 0o777) == "0o600" and oct(tdir.stat().st_mode & 0o777) == "0o700")
        else:
            check("R-win-1", "the config, the config directory and the artifact passed the owner-only ACL check",
                  out.count("ACL owner-only") >= 3 and "ACL: owner-only" in out, tail(r))
        check("R-all-8", "the config carries the token file's identity and URL, --ca-file, descriptors full, and the board user "
              "the seat's own kanban credential resolves to",
              (cfg.get("install_id"), cfg.get("seat_id"), cfg.get("sites", [{}])[0].get("ingest_url"), cfg.get("sites", [{}])[0].get("ca_file"),
               cfg.get("descriptors"), cfg.get("board_user_id"), cfg.get("board_api_base"), cfg.get("wrapped_statusline"))
              == ("inst-a", "seat-a", INGEST.url, str(INGEST.crt), "full", 14, INGEST.site + "/api/v3", "my-status-line"), json.dumps(cfg))
        after = A.settings()
        check("R-all-8", "every key of the user's settings other than hooks and env is unchanged",
              {k: v for k, v in after.items() if k not in ("hooks", "env")} == {k: v for k, v in user.items() if k not in ("hooks", "env")})
        check("R-all-8", "env keeps the user's key and gains the to-do tools key alone, as \"1\", with the marker",
              after.get("env") == {**user["env"], "CLAUDE_CODE_ENABLE_TODO_TOOLS": "1"} and Path(A.T["todoMark"]).exists(), json.dumps(after.get("env")))
        check("R-all-8", "every hook entry the user had is still first in its list, unchanged",
              all(after["hooks"][h][:len(v)] == v for h, v in user["hooks"].items()), json.dumps(after["hooks"]))
        hook_set = sorted(p.stem for p in (CO / "fixtures" / "hooks").glob("*.json"))
        js = A.T["js"]
        want_cmd = (lambda h: f'node "{js.replace(chr(92), "/")}" hook {h}') if IS_WIN else (lambda h: f"node {js} hook {h}")
        added = sorted(h for h, v in after["hooks"].items() if any(x.get("command") == want_cmd(h) for e in v for x in e.get("hooks", [])))
        check("R-all-8" if not IS_WIN else "R-win-2", "the reporter's entry is added for every hook in the fixture set, in the "
              + ("quoted forward-slash form, through a profile path holding a space" if IS_WIN else "bare absolute form"),
              added == hook_set, f"{added} != {hook_set}\n{json.dumps(after['hooks'])[:2000]}")
        check("R-all-8", "the artifact is the checkout's", Path(js).exists() and Path(js).read_bytes() == (CO / "fleet-reporter.js").read_bytes())
        if IS_LIN:
            check("R-all-5", "strace recorded the run's execs (the measurement happened): node and crontab",
                  "crontab" in trace and str(A.install) in trace)
            check("R-all-5", "the token is in no argv and no environment of any process the install started", A_TOK not in trace)
            check("R-all-5", "  … nor the seat's kanban credential", BOARD_TOKEN not in trace)
            ctl = TMP / "strace-control.log"
            subprocess.run(["strace", "-f", "-qq", "-v", "-s", "65536", "-e", "trace=execve", "-o", str(ctl), "/bin/echo", A_TOK], capture_output=True)
            check("R-all-5", "RED: the same sweep finds a token passed in an argv", A_TOK in ctl.read_text())
        else:
            not_measured("R-all-5", "strace is Linux's; the token in argv or environment is measured on the Linux runner")
        if IS_LIN:
            tab = A.crontab()
            check("R-lin-1", "the crontab holds one fleet-reporter-flusher block carrying COORD_CONFIG from settings.local.json",
                  tab.count("# BEGIN fleet-reporter-flusher\n") == 1 and "COORD_CONFIG=" in tab, tab)
            legacy = subprocess.run(["git", "-C", str(HERE), "show", "188e5fce:fleet-reporter/INSTALL-LINUX.md"], capture_output=True, text=True)
            if legacy.returncode != 0:
                check("R-lin-1", "the runbook at 188e5fce is readable (a shallow clone cannot measure the byte-identity)", False, legacy.stderr)
            else:
                lm = re.search(r"^  (LINE=.*)$", legacy.stdout, re.M)
                pm = re.search(r"^  (printf '%s\\n' '# BEGIN fleet-reporter-flusher'.*?)>> \"\$B/crontab\.new\"$", legacy.stdout, re.M | re.S)
                cc = json.loads((A.home / ".claude" / "settings.local.json").read_text())["env"]["COORD_CONFIG"]
                old = subprocess.run(["bash", "-c", f"{lm.group(1)}\n{pm.group(1)}"], capture_output=True, text=True,
                                     env={"PATH": "/usr/bin:/bin", "CC": cc, "FLOCK": A.T["flock"], "NODE": os.path.realpath(NODE), "JS": js}).stdout
                block = tab[tab.index("# BEGIN fleet-reporter-flusher"):]
                check("R-lin-1", "the block is byte-identical to the one the runbook's Step 5 wrote at 188e5fce for the same inputs",
                      bool(old) and block == old, f"{block!r}\n{old!r}")
            pid = A.wait_flusher()
            anc = A.node("ancestry")
            check("R-lin-1", "cron's line started the flusher (a process named cron, then flock), and the ancestry check PASSes",
                  pid is not None and "PASS: the lock holder was started by cron through flock" in anc.stdout, tail(anc))
        if IS_WIN:
            check("R-win-1", "the supervisor step started the flusher and waited for a live lock",
                  re.search(r"flusher (started|already running) pid \d+", out) is not None, tail(r))
            A.wait_flusher()
        if IS_MAC:
            pl = Path(A.T["plist"])
            import plistlib
            p = plistlib.loads(pl.read_bytes()) if pl.exists() else {}
            cc = json.loads((A.home / ".claude" / "settings.local.json").read_text())["env"]["COORD_CONFIG"]
            check("R-mac-1", "the plist: 0600, `command -v node`'s path, StartInterval 60, RunAtLoad, COORD_CONFIG",
                  pl.exists() and oct(pl.stat().st_mode & 0o777) == "0o600" and p.get("ProgramArguments") == [shutil.which("node", path=A.env["PATH"]), js, "flusher"]
                  and p.get("StartInterval") == 60 and p.get("RunAtLoad") is True and p.get("EnvironmentVariables") == {"COORD_CONFIG": cc}
                  and "KeepAlive" not in p, json.dumps(p, default=str))
            check("R-mac-1", "not loaded: one bootstrap", "launchctl bootstrap" in (A.stub / "launchctl.log").read_text(), (A.stub / "launchctl.log").read_text())
            A.wait_flusher()
        if r.returncode != 0 or not Path(A.T["js"]).exists():
            # Its own checks above have failed; every later section needs an installed seat, so none of them
            # runs on it — reported here by name, never read as a pass.
            not_measured("R-all-6, -7, -9, -10, -17, -18 and the per-OS sections on seat A",
                         "the first install failed (above), so there is no installed seat to run them on")
            check("seat-a", "the first install produced an installed seat for the later sections", False)
            A = None

    # ── R-all-6: the second run ───────────────────────────────────────────────────────────────────────
    if A and want("R-all-6"):
        print("== R-all-6: a second run on a current seat is a no-op ==")
        pid = A.wait_flusher()
        before, calls, n_att = A.snapshot(), len(A.calls()), len(A.attempts())
        lc = (A.stub / "launchctl.log").read_text() if IS_MAC else ""
        r = A.node()
        check("R-all-6", "it exits 0 and reports ALREADY CURRENT", r.returncode == 0 and "ALREADY CURRENT" in r.stdout, tail(r))
        check("R-all-6", "  … every file it owns keeps its bytes and its mtime", A.snapshot() == before)
        check("R-all-6", "  … the flusher was not restarted", pid is not None and A.lock_pid() == pid and pid_alive(pid), f"{pid} -> {A.lock_pid()}")
        check("R-all-6", "  … and it left no attempt directory behind", len(A.attempts()) == n_att)
        if IS_LIN:
            check("R-all-6", "  … it only read the crontab", all(c == "crontab -l" for c in A.calls()[calls:]), str(A.calls()[calls:]))
        if IS_MAC:
            new = (A.stub / "launchctl.log").read_text()[len(lc):]
            check("R-mac-1", "an equal plist, loaded: `launchd agent already current`, nothing but `print` called",
                  "launchd agent already current" in r.stdout and all(l.startswith("launchctl print") for l in new.splitlines()), new)
        r = A.node("--seat-id", "another-seat")
        check("R-all-6", "a run naming another seat than the config's refuses, NOTHING RUN, and changes nothing",
              r.returncode == 1 and "NOTHING RUN" in r.stderr and A.snapshot() == before, tail(r))

    # ── R-all-7: an upgrade, artifact first and the stop second ───────────────────────────────────────
    if A and want("R-all-7", "R-mac-1"):
        print("== R-all-7: an upgrade replaces the artifact first, stops the flusher second, and checks the holder ==")
        pid = A.wait_flusher()
        Path(A.T["js"]).write_text(Path(A.T["js"]).read_text() + "\n// an older build\n")
        time.sleep(1.1)                     # the holder's start must read as before the old artifact's mtime
        r = A.node()
        out = r.stdout
        i_art, i_stop = out.find("── step `artifact`"), out.find(f"flusher pid {pid} exited")
        check("R-all-7", "artifact replaced FIRST, the old flusher stopped SECOND, then the holder check PASSes on a new pid",
              r.returncode == 0 and "INSTALLED:" in out and Path(A.T["js"]).read_bytes() == (CO / "fleet-reporter.js").read_bytes()
              and 0 <= i_art < i_stop and "PASS: the lock holder runs the artifact installed at" in out and A.lock_pid() not in (None, pid), tail(r))
        if IS_LIN:
            check("R-all-7", "  … and the crontab, byte-equal, read `crontab already current`", "crontab already current" in out, tail(r))
        if IS_MAC:
            log = (A.stub / "launchctl.log").read_text()
            check("R-mac-1", "a restart owed: `kickstart -k` on the label, after the stop by pid", "kickstart -k" in log
                  and "kickstart: the previous pid was already stopped" in log, log[-1500:])
        A.wait_flusher()
        Path(A.T["js"]).unlink()                 # the script gone, its fixtures intact: a difference, never a crash
        r = A.node("artifact")
        check("R-all-7", "a seat whose installed script is missing beside intact fixtures: the artifact step installs it",
              r.returncode == 0 and Path(A.T["js"]).read_bytes() == (CO / "fleet-reporter.js").read_bytes() and "fixtures-match" in r.stdout, tail(r))
        r = A.node()
        check("R-all-7", "  … and the next run restarts the flusher on it", r.returncode == 0 and "PASS: the lock holder runs the artifact" in r.stdout, tail(r))
        A.wait_flusher()

    # ── R-all-7 on Linux: a seat laid out by install.sh ───────────────────────────────────────────────
    if IS_LIN and want("R-all-7"):
        print("== R-all-7: a seat laid out by install.sh upgrades in place ==")
        B = Seat("seat-b")
        tb = token()
        INGEST.accepted.add(tb)
        # The layout install.sh's blocks left: the multi-site config write-config.js wrote, the bare hook form
        # wire-hooks.js wrote, the crontab block of the runbook at 188e5fce, the to-do key and its marker, and an
        # artifact of an earlier build. The config is written by the export, which is the same writer.
        Path(B.T["artifactDir"]).mkdir(parents=True)
        (Path(B.T["attempts"]) / "20261028T000000Z").mkdir(parents=True)     # install.sh's attempt directory, under ~/.local/state
        shutil.copytree(CO / "fixtures", Path(B.T["artifactDir"]) / "fixtures")
        Path(B.T["js"]).write_text((CO / "fleet-reporter.js").read_text() + "\n// the build install.sh installed\n")
        r = node_eval("const m=require(process.argv[1]); m.writeConfig({config:process.argv[2], token:process.argv[3], install:'inst-b', seat:'seat-b', url:process.argv[4], spool:process.argv[5], caFile:process.argv[6], descriptors:'full'});",
                      B.T["config"], tb, INGEST.url, B.T["spool"], str(INGEST.crt))
        check("R-all-7", "precondition: the install.sh layout's config is written", r.returncode == 0 and Path(B.T["config"]).exists(), tail(r))
        hooks = {}
        for f in sorted((CO / "fixtures" / "hooks").glob("*.json")):
            shapes = json.loads(f.read_text())["shapes"]
            hooks[f.stem] = [{"matcher": "*" if all(isinstance(p.get("tool_name"), str) for p in shapes) else "",
                              "hooks": [{"type": "command", "command": f"node {B.T['js']} hook {f.stem}"}]}]
        Path(B.T["settings"]).write_text(json.dumps({"hooks": hooks, "env": {"CLAUDE_CODE_ENABLE_TODO_TOOLS": "1"}}, indent=2) + "\n")
        Path(B.T["todoMark"]).write_text("2026-10-28T00:00:00Z\n")
        legacy = subprocess.run(["git", "-C", str(HERE), "show", "188e5fce:fleet-reporter/INSTALL-LINUX.md"], capture_output=True, text=True).stdout
        lm = re.search(r"^  (LINE=.*)$", legacy, re.M)
        pm = re.search(r"^  (printf '%s\\n' '# BEGIN fleet-reporter-flusher'.*?)>> \"\$B/crontab\.new\"$", legacy, re.M | re.S)
        cc = json.loads((B.home / ".claude" / "settings.local.json").read_text())["env"]["COORD_CONFIG"]
        (B.stub / "installed").write_text("MAILTO=\"\"\n" + subprocess.run(["bash", "-c", f"{lm.group(1)}\n{pm.group(1)}"], capture_output=True, text=True,
                                          env={"PATH": "/usr/bin:/bin", "CC": cc, "FLOCK": B.T["flock"], "NODE": os.path.realpath(NODE), "JS": B.T["js"]}).stdout)
        settings_before = Path(B.T["settings"]).read_bytes()
        pid = B.wait_flusher()
        time.sleep(1.1)
        r = B.node()
        out = r.stdout
        check("R-all-7", "the install.sh layout: `crontab already current`, `already wired: nothing applied`, the to-do key "
              "`already set`, the artifact replaced and the holder PASS, INSTALLED",
              r.returncode == 0 and "crontab already current" in out and "already wired: nothing applied" in out
              and "already set: unchanged" in out and "INSTALLED:" in out and "PASS: the lock holder runs the artifact" in out
              and Path(B.T["settings"]).read_bytes() == settings_before and pid is not None,
              str({k: k in out for k in ("crontab already current", "already wired: nothing applied", "already set: unchanged", "INSTALLED:",
                                         "PASS: the lock holder runs the artifact")}) + f" settings equal {Path(B.T['settings']).read_bytes() == settings_before} pid {pid} rc {r.returncode}\n"
              + "\n".join(ln for ln in (r.stdout + r.stderr).splitlines() if not ln.startswith((" ", "{", "}"))))

    # ── R-all-18 / R-all-9: add a site, rotate, remove a site ─────────────────────────────────────────
    if A and want("R-all-18", "R-all-9"):
        print("== R-all-18 / R-all-9: add-site, the rotation's refusals and its match, remove-site ==")
        cfg_before = Path(A.T["config"]).read_bytes()
        tb = token()
        INGEST2.accepted.add(tb)
        fb = write_v2(A.root / "carry" / "site-b", tb, INGEST2.url, "inst-a", "seat-a")
        r = A.node("add-site", "--token-file", str(fb), "--ca-file", str(INGEST2.crt), "--site-name", "site-b")
        cfg = A.config()
        check("R-all-18", "add-site with a v2 file and --ca-file: the site added with that ca_file, the file taken and removed, the "
              "flusher restarted",
              r.returncode == 0 and [s["name"] for s in cfg["sites"]] == ["default", "site-b"] and cfg["sites"][1]["ca_file"] == str(INGEST2.crt)
              and not fb.exists() and not Path(A.T["tokenFile"]).exists() and "PASS: the lock holder runs the artifact" in r.stdout, tail(r))
        h = A.node("health")
        check("R-all-18", "  … and the probe trusts it: both sites current", h.returncode == 0 and "site-b: installed" in h.stdout and "health rc=0" in h.stdout, tail(h))
        fv1 = A.root / "carry" / "v1"
        fv1.write_bytes((token() + "\n").encode())
        r = A.node("add-site", "--token-file", str(fv1))
        check("R-all-18", "add-site with a v1 file and no --site: refused naming it, the file left", r.returncode == 1 and "--site" in r.stderr and fv1.exists(), tail(r))
        tc = token()
        INGEST3.accepted.add(tc)
        fv1.write_bytes((tc + "\n").encode())
        r = A.node("add-site", "--token-file", str(fv1), "--site", INGEST3.site, "--ca-file", str(INGEST3.crt), "--site-name", "site-c")
        check("R-all-18", "  … with --site: the site added, the file taken and removed as the main invocation does",
              r.returncode == 0 and [s["name"] for s in A.config()["sites"]] == ["default", "site-b", "site-c"] and not fv1.exists(), tail(r))
        r = A.node("add-site", "--token-file", str(write_v2(A.root / "carry" / "dup", token(), INGEST2.url, "inst-a", "seat-a")))
        check("R-all-18", "add-site for a site already configured: refused with the listing", r.returncode == 1 and "already configured" in r.stderr and "site site-b" in r.stderr, tail(r))
        # R-all-9 — rotation.
        before = Path(A.T["config"]).read_bytes()
        nomatch = write_v2(A.root / "carry" / "nomatch", token(), "https://elsewhere.test/api/ingest/events", "inst-a", "seat-a")
        r = A.node("--token-file", str(nomatch))
        check("R-all-9", "a v2 file matching no site: refused with the listing and the add-site pointer, the config byte-equal, the file left",
              r.returncode == 1 and "site default:" in r.stderr and "add-site" in r.stderr and Path(A.T["config"]).read_bytes() == before and nomatch.exists(), tail(r))
        nb = token()
        INGEST2.accepted.add(nb)
        fb2 = write_v2(A.root / "carry" / "rot-b", nb, INGEST2.url.replace("localhost", "LOCALHOST"), "inst-a", "seat-a")
        r = A.node("--token-file", str(fb2), "--site-name", "default")
        check("R-all-9", "a file matching site-b with --site-name default: refused, the config byte-equal", r.returncode == 1 and Path(A.T["config"]).read_bytes() == before, tail(r))
        wrong = write_v2(A.root / "carry" / "wrong-id", token(), INGEST2.url, "inst-a", "seat-z")
        r = A.node("--token-file", str(wrong))
        check("R-all-9", "a mismatched identity: refused, the config byte-equal", r.returncode == 1 and "identity differs" in r.stderr and Path(A.T["config"]).read_bytes() == before, tail(r))
        a_site = json.loads(before)["sites"][0]
        r = A.node("--token-file", str(fb2))
        cfg = A.config()
        check("R-all-9", "a file matching site-b by a URL differing only in host case: site-b rotated, default untouched, the flusher "
              "restarted, the revoke reminder printed",
              r.returncode == 0 and cfg["sites"][1]["token"] == nb and cfg["sites"][0] == a_site and "token replaced: site-b" in r.stdout
              and "Revoke the old token" in r.stdout and "PASS: the lock holder runs the artifact" in r.stdout and not fb2.exists(), tail(r))
        INGEST2.accepted.discard(tb)
        # remove-site
        r = A.node("remove-site", "site-c")
        check("R-all-18", "remove-site: the site removed, the flusher restarted", r.returncode == 0 and [s["name"] for s in A.config()["sites"]] == ["default", "site-b"]
              and "PASS: the lock holder runs the artifact" in r.stdout, tail(r))
        r = A.node("remove-site", "nope")
        check("R-all-18", "remove-site of a name the config does not carry: refused, naming the sites", r.returncode == 1 and "default, site-b" in r.stderr, tail(r))
        r = A.node("remove-site")
        check("R-all-18", "remove-site with no name: refused by name, no TypeError", r.returncode == 1 and "needs the site's name" in r.stderr and "TypeError" not in r.stderr, tail(r))
        # set-descriptors
        before = Path(A.T["config"]).read_bytes()
        r = A.node("set-descriptors", "most")
        check("R-all-18", "set-descriptors outside the three: refused, config byte-equal", r.returncode == 1 and Path(A.T["config"]).read_bytes() == before, tail(r))
        r = A.node("set-descriptors", "paths")
        check("R-all-18", "set-descriptors paths: the one key rewritten, mode kept, nothing restarted",
              r.returncode == 0 and A.config()["descriptors"] == "paths" and "step `restart`" not in r.stdout
              and (IS_WIN or oct(Path(A.T["config"]).stat().st_mode & 0o777) == "0o600"), tail(r))
        A.node("set-descriptors", "full")
        # statusline
        if IS_WIN:
            r = A.node("statusline")
            check("R-all-18", "statusline on win32: refused, naming the unmeasured wrap", r.returncode == 1 and "nothing measured it" in r.stderr, tail(r))
        else:
            s0 = A.settings()
            s0["statusLine"]["command"] = "someone-elses-line"
            Path(A.T["settings"]).write_text(json.dumps(s0, indent=2) + "\n")
            before = Path(A.T["settings"]).read_bytes()
            r = A.node("statusline")
            check("R-all-18", "statusline when the live statusLine is neither the reporter nor the prior command: refused, nothing written",
                  r.returncode == 1 and "not the prior command" in r.stderr and Path(A.T["settings"]).read_bytes() == before, tail(r))
            s0["statusLine"]["command"] = "my-status-line"
            Path(A.T["settings"]).write_text(json.dumps(s0, indent=2) + "\n")
            r = A.node("statusline")
            check("R-all-18", "statusline: the wrap applied, the config's wrapped_statusline is the prior command",
                  r.returncode == 0 and A.settings()["statusLine"]["command"] == f"node {A.T['js']} statusline" and A.config()["wrapped_statusline"] == "my-status-line", tail(r))
            N = Seat("no-statusline", coord=False)
            N.node("artifact")
            node_eval("const m=require(process.argv[1]); m.writeConfig({config:process.argv[2], token:'mzn_'+'n'.repeat(43), install:'inst-n', seat:'seat-n', url:'https://n.test/api/ingest/events', spool:process.argv[3]});",
                      N.T["config"], N.T["spool"])
            Path(N.T["settings"]).write_text('{"permissions": {"allow": []}}\n')
            r = N.node("statusline")
            check("R-all-18", "statusline on a seat that has no statusLine at all: the wrap applied, nothing else changed, "
                  "wrapped_statusline null", r.returncode == 0 and N.settings() == {"permissions": {"allow": []}, "statusLine": {"type": "command", "command": f"node {N.T['js']} statusline"}}
                  and N.config()["wrapped_statusline"] is None, tail(r))
        A.wait_flusher()

    # ── R-all-17: the checkout's reporter validates the write ─────────────────────────────────────────
    if A and want("R-all-17"):
        print("== R-all-17: a config the checkout's reporter refuses is never written ==")
        d = planted_copy("r17", ("fleet-reporter.js", "  if (typeof c.enabled !== 'boolean') errors.push('enabled must be a boolean');",
                                 "  if (typeof c.enabled !== 'boolean') errors.push('enabled must be a boolean');\n  if (c.descriptors === 'none') errors.push('planted: this checkout refuses descriptors none');"))
        before = Path(A.T["config"]).read_bytes()
        r = A.node("set-descriptors", "none", install=d / "install.js")
        check("R-all-17", "the checkout's loadConfig refuses a value the installed artifact accepts: the write is refused, the config byte-equal",
              r.returncode == 1 and "planted: this checkout refuses" in r.stderr and Path(A.T["config"]).read_bytes() == before, tail(r))
        r = A.node("set-descriptors", "none")
        check("R-all-17", "  … and the real checkout writes it", r.returncode == 0 and A.config()["descriptors"] == "none", tail(r))
        A.node("set-descriptors", "full")

    # ── R-all-16: the atomic write ────────────────────────────────────────────────────────────────────
    if want("R-all-16"):
        print("== R-all-16: the atomic write ==")
        d = TMP / "atomic"
        d.mkdir()
        tgt = d / "target.json"
        tgt.write_text('{"a": 1}\n')
        r = node_eval("const m=require(process.argv[1]); const fs=require('fs'); const real=fs.renameSync; fs.renameSync=()=>{const e=new Error('planted'); e.code='EIO'; throw e;};"
                      " const ok=m.atomicWrite(process.argv[2], 'changed'); fs.renameSync=real; console.log(JSON.stringify({ok, left: fs.readdirSync(require('path').dirname(process.argv[2]))}));", str(tgt))
        res = json.loads(r.stdout or "{}")
        check("R-all-16", "a write whose rename fails returns false, the target untouched and no temp file left",
              res.get("ok") is False and tgt.read_text() == '{"a": 1}\n' and res.get("left") == ["target.json"], r.stdout + r.stderr)
        r = node_eval("const m=require(process.argv[1]); const fs=require('fs'); const real=fs.renameSync; let n=0; fs.renameSync=(a,b)=>{ if (n++===0) { const e=new Error('planted'); e.code='EBUSY'; throw e; } return real(a,b); };"
                      " console.log(JSON.stringify({ok: m.atomicWrite(process.argv[2], 'retried'), n}));", str(tgt))
        res = json.loads(r.stdout or "{}")
        if IS_WIN:
            check("R-all-16", "on win32 a planted EBUSY on the first rename is retried and succeeds", res == {"ok": True, "n": 2} and tgt.read_text() == "retried", r.stdout + r.stderr)
        else:
            check("R-all-16", "off win32 an EBUSY is not retried: the write returns false and the target is untouched",
                  res == {"ok": False, "n": 1} and tgt.read_text() == '{"a": 1}\n', r.stdout + r.stderr)
        if not IS_WIN:
            S = Seat("symlink", coord=False)
            real = S.root / "dotfiles" / "settings.json"
            real.parent.mkdir()
            real.write_text("{}\n")
            os.chmod(real, 0o640)
            Path(S.T["settings"]).symlink_to(real)
            S.node("artifact")
            r = S.node("hooks")
            check("R-all-16", "a settings.json that is a symlink keeps being one, its target gets the bytes, and its 0640 mode survives",
                  r.returncode == 0 and Path(S.T["settings"]).is_symlink() and "hooks" in json.loads(real.read_text())
                  and oct(real.stat().st_mode & 0o777) == "0o640", tail(r))

    # ── R-all-21: the render has no side effects ──────────────────────────────────────────────────────
    if IS_LIN and want("R-all-21", "R-all-20"):
        print("== R-all-21: --render-shipping spawns nothing and writes nothing ==")
        rh, empty = TMP / "render-home", TMP / "render-path"
        rh.mkdir()
        empty.mkdir()
        inp = json.dumps({"home": "/home/agent", "win32_home": "C:\\Users\\agent", "node": "/usr/bin/node", "coord_config": "/c.json",
                          "fixtures": ["Stop"], "fixture_shapes": {"Stop": []}})

        def render(js: Path) -> tuple:
            log = TMP / f"strace-render-{js.parent.name}.log"
            r = subprocess.run([shutil.which("strace"), "-f", "-qq", "-e", "trace=execve,openat,creat,mkdir,unlink,rename", "-o", str(log),
                                NODE, str(js), "--render-shipping"], input=inp, capture_output=True, text=True, env={"HOME": str(rh), "PATH": str(empty)})
            t = log.read_text()
            execs = [ln for ln in t.splitlines() if "execve(" in ln]
            writes = [ln for ln in t.splitlines() if re.search(r"openat\(.*O_(WRONLY|RDWR|CREAT)", ln) and "/dev/" not in ln]
            return r, execs, writes
        r, execs, writes = render(INSTALL)
        check("R-all-21", "under strace, with a temporary HOME and a PATH holding no crontab: one exec (node itself), no file opened "
              "for writing, the HOME untouched", r.returncode == 0 and len(execs) == 1 and writes == [] and list(rh.iterdir()) == [],
              f"{execs}\n{writes}\n{tail(r)}")
        d = planted_copy("r21", ("install.js", "  const out = {};\n  for (const platform of ['linux', 'darwin', 'win32']) {",
                                 "  const out = {};\n  cp.spawnSync('crontab', ['-l']);\n  for (const platform of ['linux', 'darwin', 'win32']) {"))
        r2, execs2, _ = render(d / "install.js")
        check("R-all-21", "RED: a render planted to spawn crontab is caught by the empty PATH and the trace (an exec attempted)",
              len(execs2) > 1 and any("crontab" in e for e in execs2), str(execs2))
        r = subprocess.run([NODE, str(INSTALL), "--render-shipping", "--uninstall"], input=inp, capture_output=True, text=True, env={"HOME": str(rh), "PATH": str(empty)})
        check("R-all-21", "--render-shipping with any other flag: refused, nothing run", r.returncode == 1 and "takes no other argument" in r.stderr and list(rh.iterdir()) == [], tail(r))
        r = subprocess.run([NODE, str(INSTALL), "--render-shipping"], input=json.dumps({**json.loads(inp), "a_key_from_the_future": 1}), capture_output=True, text=True, env={"HOME": str(rh), "PATH": str(empty)})
        r0 = subprocess.run([NODE, str(INSTALL), "--render-shipping"], input=inp, capture_output=True, text=True, env={"HOME": str(rh), "PATH": str(empty)})
        check("R-all-20", "a render input carrying an unknown key renders unchanged", r.returncode == 0 and r.stdout == r0.stdout, tail(r))

    # ── R-all-22: issue-file ──────────────────────────────────────────────────────────────────────────
    if want("R-all-22"):
        print("== R-all-22: issue-file ==")
        S = Seat("issue", coord=False)
        tk = token()
        issue = f" seat inst-a / seat-a (seat_ref 1)\n prefix {tk[:12]}\n note {token()}\n\n  token       {tk}\n"
        out_f = S.root / "issued"
        r = S.node("issue-file", "--out", str(out_f), stdin=issue)
        check("R-all-22", "a piped issue output with one token line: the file written 0600 holding the token alone, the other "
              "lines echoed with every token-shaped substring <redacted>, the prefix printed, no token printed",
              r.returncode == 0 and out_f.exists() and out_f.read_text() == tk + "\n" and (IS_WIN or oct(out_f.stat().st_mode & 0o777) == "0o600")
              and "note <redacted>" in r.stdout and f"prefix {tk[:12]}" in r.stdout and not any(t in r.stdout + r.stderr for t in SECRETS), tail(r))
        for label, text in {"zero token lines": " prefix x\n", "two token lines": f"  token {tk}\n  token {tk}\n"}.items():
            o = S.root / f"issued-{label.split()[0]}"
            r = S.node("issue-file", "--out", str(o), stdin=text)
            check("R-all-22", f"{label}: refused, nothing written", r.returncode == 1 and not o.exists() and tk not in r.stdout + r.stderr, tail(r))
        r = S.node("issue-file", "--out", str(out_f), stdin=issue)
        check("R-all-22", "an --out that exists: refused, the file untouched", r.returncode == 1 and out_f.read_text() == tk + "\n", tail(r))
        if IS_WIN:
            r = S.node("issue-file", "--out", str(S.root / "p"), "--prompt")
            check("R-all-22", "--prompt on win32: refused by name", r.returncode == 1 and "Windows does not have" in r.stderr, tail(r))
            not_measured("R-all-22 (terminal stdin)", "a terminal stdin under mintty can read as a pipe; the POSIX refusal is measured on Linux and macOS")
        else:
            import pty
            import termios

            def at_pty(args: list, answer: "bytes | None", plant_sigint: bool = False) -> tuple:
                pid, fd = pty.fork()
                if pid == 0:
                    os.execve(NODE, [NODE, *args], S.env)
                out, echo_at_read, sent = b"", None, False
                t0 = time.time()
                while time.time() - t0 < 30:
                    try:
                        import select
                        rd, _, _ = select.select([fd], [], [], 5)
                        if not rd:
                            if not sent:
                                break
                            continue
                        chunk = os.read(fd, 4096)
                    except OSError:
                        break
                    if not chunk:
                        break
                    out += chunk
                    if not sent and b"Paste the token" in out:
                        t1 = time.time()
                        while termios.tcgetattr(fd)[3] & termios.ECHO and time.time() - t1 < 5:
                            time.sleep(0.01)
                        echo_at_read = not termios.tcgetattr(fd)[3] & termios.ECHO
                        if plant_sigint:
                            os.write(fd, b"mzn_par")
                            time.sleep(0.3)
                            os.kill(pid, signal.SIGINT)
                        elif answer is not None:
                            os.write(fd, answer)
                        sent = True
                _, st = os.waitpid(pid, 0)
                echo_after = bool(termios.tcgetattr(fd)[3] & termios.ECHO)
                os.close(fd)
                OUTPUTS.append(out.decode("utf-8", "replace"))
                return os.waitstatus_to_exitcode(st), out, echo_at_read, echo_after

            tp = token()
            pf = S.root / "prompted"
            rc, out, e_read, e_after = at_pty([str(S.install), "issue-file", "--out", str(pf), "--prompt"], (tp + "\r").encode())
            check("R-all-22", "--prompt at a pseudo-terminal: the token read with echo off, written, never shown, the terminal mode restored",
                  rc == 0 and pf.exists() and pf.read_text() == tp + "\n" and e_read is True and e_after is True and tp.encode() not in out, out.decode(errors="replace")[-800:])
            rc, out, e_read, e_after = at_pty([str(S.install), "issue-file", "--out", str(S.root / "p-sig"), "--prompt"], None, plant_sigint=True)
            check("R-all-22", "  … after SIGINT mid-read: nothing written, the terminal mode restored", rc != 0 and not (S.root / "p-sig").exists() and e_after is True, f"rc={rc} {out[-400:]!r}")
            d = planted_copy("r22", ("install.js", "          if (ch === '\\u007f' || ch === '\\b') buf = buf.slice(0, -1); else buf += ch;",
                                     "          if (buf.length > 5) throw new Error('planted failure mid-read');\n          if (ch === '\\u007f' || ch === '\\b') buf = buf.slice(0, -1); else buf += ch;"))
            rc, out, e_read, e_after = at_pty([str(d / "install.js"), "issue-file", "--out", str(S.root / "p-fail"), "--prompt"], (token() + "\r").encode())
            check("R-all-22", "  … after a planted failure mid-read: nothing written, the terminal mode restored",
                  rc != 0 and not (S.root / "p-fail").exists() and e_after is True and b"planted failure" in out, out.decode(errors="replace")[-600:])
            # stdin a terminal, no --prompt: refused (POSIX)
            rc, out, _, _ = at_pty([str(S.install), "issue-file", "--out", str(S.root / "p-tty")], None)
            check("R-all-22", "a stdin that is a terminal, without --prompt: refused (POSIX)", rc == 1 and b"pipe the issue command in" in out and not (S.root / "p-tty").exists(), out.decode(errors="replace")[-400:])

    # ── R-all-15: the install.sh shim ─────────────────────────────────────────────────────────────────
    if not IS_WIN and want("R-all-15"):
        print("== R-all-15: install.sh is a shim for one release ==")
        S = Seat("shim", coord=False)
        r = subprocess.run(["bash", str(CO / "install.sh"), "--set-token", "--site", "https://x.test"], capture_output=True, text=True, env=S.env)
        check("R-all-15", "install.sh --set-token: refused naming --token-file, nothing run", r.returncode == 1 and "--token-file" in r.stderr
              and not Path(S.T["configDir"]).exists() and not S.attempts(), tail(r))
        r = subprocess.run(["bash", str(CO / "install.sh"), "--paths"], capture_output=True, text=True, env=S.env)
        check("R-all-15", "install.sh with any other arguments runs install.js with them", r.returncode == 0 and json.loads(r.stdout)["config"] == S.T["config"], tail(r))

    # ── Windows ───────────────────────────────────────────────────────────────────────────────────────
    if IS_WIN and A and want("R-win-1"):
        print("== R-win-1: with the start disabled, NO FLUSHER, and arrival is never reached ==")
        W = Seat("seat-w-nostart")
        tw = token()
        INGEST.accepted.add(tw)
        d = planted_copy("rw1", ("install.js", "  child.unref();\n", "  child.kill();\n"))
        cp_ = run_to_files([NODE, str(d / "install.js"), "--token-file", str(write_v2(W.root / "t", tw, INGEST.url, "inst-w", "seat-w")), "--ca-file", str(INGEST.crt)],
                           W.env, W.root, timeout=600)
        check("R-win-1", "the supervisor step with its start disabled ends NO FLUSHER exit 1, and the arrival step never runs",
              cp_.returncode == 1 and "NO FLUSHER" in cp_.stderr and "step `arrival`" not in cp_.stdout, tail(cp_))
    if IS_WIN and A and want("R-win-3", "R-all-7"):
        print("== R-win-3 / R-all-7: card#12335's hand-run layout upgrades with settings.json byte-equal ==")
        s = A.settings()
        js = A.T["js"]
        fwd = js.replace("\\", "/")
        for h in list(s["hooks"]):
            for e in s["hooks"][h]:
                for x in e["hooks"]:
                    if x["command"].startswith("node ") and "fleet-reporter.js" in x["command"]:
                        x["command"] = f'node "{fwd}" hook {h}'
        Path(A.T["settings"]).write_text(json.dumps(s, indent=4) + "\n")      # hand-formatted: a rewrite would show
        before = Path(A.T["settings"]).read_bytes()
        Path(js).write_text(Path(js).read_text() + "\n// the hand-run build\n")
        time.sleep(1.1)
        r = A.node()
        check("R-win-3", "an upgrade over the documented quoted forward-slash form leaves settings.json BYTE-EQUAL (no second entry per hook)",
              r.returncode == 0 and Path(A.T["settings"]).read_bytes() == before and "already wired: nothing applied" in r.stdout, tail(r))
        check("R-all-7", "  … the artifact replaced, the ACLs re-checked, the flusher started, the holder PASS",
              Path(js).read_bytes() == (CO / "fleet-reporter.js").read_bytes() and "ACL owner-only" in r.stdout
              and "PASS: the lock holder runs the artifact" in r.stdout, tail(r))
        s = A.settings()
        s["hooks"]["Stop"].append({"matcher": "", "hooks": [{"type": "command", "command": f'node "{js}" hook Stop'}]})
        Path(A.T["settings"]).write_text(json.dumps(s, indent=4) + "\n")
        r = A.node("hooks")
        stops = [x["command"] for e in A.settings()["hooks"]["Stop"] for x in e["hooks"] if "fleet-reporter.js" in x["command"]]
        check("R-win-3", "a backslash-form entry planted beside them is recognised as the reporter's: collapsed, one entry per hook",
              r.returncode == 0 and stops == [f'node "{fwd}" hook Stop'], f"{stops}\n{tail(r)}")
    if IS_WIN and want("R-win-4"):
        S = Seat("roaming", coord=False)
        f = write_v2(S.root / "t", token(), INGEST.url, "inst-r", "seat-r")
        r = S.node("--token-file", str(f), env={**S.env, "FLEET_REPORTER_INSTALL_ASSUME_ROAMING": "1"})
        check("R-win-4", "a roaming profile: refused by name before anything is written",
              r.returncode == 1 and "roaming profile" in r.stderr and not S.attempts() and not Path(S.T["configDir"]).exists() and f.exists(), tail(r))

    # ── macOS ─────────────────────────────────────────────────────────────────────────────────────────
    if IS_MAC and A and want("R-mac-1"):
        print("== R-mac-1: a loaded agent whose plist differs is booted out, rewritten, bootstrapped ==")
        lsl = A.home / ".claude" / "settings.local.json"
        moved = A.home / ".config" / "coord" / "moved.json"
        shutil.copy(A.home / ".config" / "coord" / "coordination.config.json", moved)
        lsl.write_text(json.dumps({"env": {"COORD_CONFIG": str(moved)}}))
        n = len((A.stub / "launchctl.log").read_text())
        r = A.node()
        new = (A.stub / "launchctl.log").read_text()[n:]
        import plistlib
        check("R-mac-1", "loaded, differing: bootout, write, bootstrap — the plist carries the new COORD_CONFIG",
              r.returncode in (0, 2) and "launchctl bootout" in new and "launchctl bootstrap" in new and new.index("bootout") < new.index("bootstrap")
              and plistlib.loads(Path(A.T["plist"]).read_bytes())["EnvironmentVariables"]["COORD_CONFIG"] == str(moved), new + tail(r))
        A.wait_flusher()
    if IS_MAC and A and want("R-mac-2", "R-mac-3"):
        print("== R-mac-2 / R-mac-3: ancestry by launchctl's pid, and flusher control on darwin ==")
        r = A.node("ancestry")
        check("R-mac-2", "launchctl reports the lock's pid: PASS", "PASS: launchctl reports pid" in r.stdout, tail(r))
        lock = Path(A.T["spool"]) / "flusher.lock"
        real_lock = lock.read_text()
        sleeper = subprocess.Popen(["sleep", "300"])
        try:
            lock.write_text(json.dumps({"pid": sleeper.pid, "started_at": "2026-10-29T00:00:00.000Z"}))
            r = A.node("ancestry")
            check("R-mac-2", "a planted lock pid launchctl does not report: FAIL", "FAIL: launchctl reports" in r.stdout, tail(r))
            # The one stale-lock test (`lockHolder`), read through the read-only holder step: a pid that does not
            # run this artifact's flusher names no flusher, and is never signalled (the stop is R-all-10's RED).
            r = A.node("holder")
            check("R-mac-3", "a lock naming a reused pid (a process not running the artifact): no live flusher, stale, nothing "
                  "signalled", r.returncode == 0 and "the lock is stale" in r.stdout and "reused pid" in r.stdout and sleeper.poll() is None, tail(r))
            stand = subprocess.Popen([NODE, "-e", "setTimeout(()=>{},300000)", "/elsewhere/fleet-reporter.js", "flusher"])
            lock.write_text(json.dumps({"pid": stand.pid, "started_at": "2026-10-29T00:00:00.000Z"}))
            r = A.node("holder")
            check("R-mac-3", "a stand-in `node … flusher` not running this artifact: not a flusher of it, nothing signalled",
                  r.returncode == 0 and "the lock is stale" in r.stdout and stand.poll() is None, tail(r))
            stand.kill()
        finally:
            sleeper.kill()
            lock.write_text(real_lock)
        held = json.loads(real_lock)
        os.utime(A.T["js"], (time.time() + 3600, time.time() + 3600))
        r = A.node("holder")
        check("R-mac-3", "a holder started before the artifact: FAIL, it runs the previous build", r.returncode == 1 and "it runs the previous build" in r.stdout, tail(r))
        os.utime(A.T["js"], None)
    if IS_MAC and want("R-mac-4"):
        S = Seat("nogui", coord=False)
        (S.stub / "no-gui").write_text("")
        f = write_v2(S.root / "t", token(), INGEST.url, "inst-g", "seat-g")
        r = S.node("--token-file", str(f))
        check("R-mac-4", "no gui/<uid> domain: refused by name before anything is written",
              r.returncode == 1 and "no gui launchd domain" in r.stderr and not S.attempts() and not Path(S.T["configDir"]).exists() and f.exists(), tail(r))

    # ── refusals that come before anything is written, and settings that are not an object ─────────────
    if want("R-all-8", "R-all-2"):
        print("== a settings.json that is not a JSON object; a home or a COORD_CONFIG the install cannot carry ==")
        S = Seat("bad-settings", coord=False)
        S.node("artifact")
        for body in ("[]\n", "null\n", '"x"\n'):
            Path(S.T["settings"]).write_text(body)
            r = S.node("hooks")
            check("R-all-8", f"a settings.json holding {body.strip()} is refused by name, nothing written, no TypeError",
                  r.returncode == 1 and "is not a JSON object" in r.stderr and "TypeError" not in r.stderr
                  and Path(S.T["settings"]).read_text() == body, tail(r))
        if not IS_WIN:
            H = Seat("space-home", home_name="a home", coord=False)
            f = write_v2(H.root / "t", token(), INGEST.url, "inst-h", "seat-h")
            r = H.node("--token-file", str(f))
            check("R-all-2", "a POSIX home holding whitespace is refused at prereqs, before anything is written",
                  r.returncode == 1 and "holds whitespace or a quote" in r.stderr and not H.attempts()
                  and not Path(H.T["configDir"]).exists() and f.exists(), tail(r))
        if IS_LIN:
            C = Seat("odd-coord", coord=False)
            (C.home / ".claude" / "settings.local.json").write_text(json.dumps({"env": {"COORD_CONFIG": "/tmp/$(id)/c.json"}}))
            f = write_v2(C.root / "t", token(), INGEST.url, "inst-c", "seat-c")
            r = C.node("--token-file", str(f))
            check("R-all-2", "a COORD_CONFIG the crontab line cannot carry is refused BY NAME at prereqs, before anything is written",
                  r.returncode == 1 and "COORD_CONFIG" in r.stderr and "cannot carry" in r.stderr and not C.attempts() and f.exists(), tail(r))
        if IS_WIN:
            J = Seat("junction", coord=False)
            target = J.root / "elsewhere"
            target.mkdir()
            subprocess.run(["cmd", "/c", "mklink", "/J", J.T["configDir"], str(target)], capture_output=True)
            before = subprocess.run(["icacls", str(target)], capture_output=True, text=True).stdout
            f = write_v2(J.root / "t", token(), INGEST.url, "inst-j", "seat-j")
            r = J.node("--token-file", str(f))
            check("R-win-1", "a config directory that is a junction is refused before icacls runs, and its target's ACL is unchanged",
                  Path(J.T["configDir"]).exists() and r.returncode == 1 and "never re-ACL'd" in r.stderr
                  and subprocess.run(["icacls", str(target)], capture_output=True, text=True).stdout == before, tail(r))

    # ── R-all-10: a stale lock whose pid was reused never blocks an uninstall ───────────────────────────
    if want("R-all-10"):
        print("== R-all-10: a stale flusher.lock naming a live process that is not the flusher ==")
        # The flusher died (a reboot; Windows has no supervisor) and its lock outlived it, older than
        # K.LOCK_STALE_MS, its pid now another live process's. That lock names no flusher: the uninstall signals
        # nothing, and finishes.
        S = Seat("stale-lock", coord=False)
        S.node("artifact")
        sleeper = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(300)"])
        try:
            spool = Path(S.T["spool"])
            spool.mkdir(parents=True, exist_ok=True)
            lock = spool / "flusher.lock"
            lock.write_text(json.dumps({"pid": sleeper.pid, "started_at": "2026-01-01T00:00:00.000Z"}))
            os.utime(lock, (time.time() - 600, time.time() - 600))
            r = S.node("--uninstall")
            check("R-all-10", "--uninstall over a stale lock whose pid now runs something else: it signals nothing, says there "
                  "is no flusher to stop, removes the spool and the artifact, and exits 0",
                  r.returncode == 0 and "no flusher to stop" in r.stdout and sleeper.poll() is None
                  and not spool.exists() and not Path(S.T["artifactDir"]).exists(), tail(r))
        finally:
            sleeper.kill()
            sleeper.wait()

    # ── R-all-12: AGENT-INSTALL.md's prose rules ──────────────────────────────────────────────────────
    if want("R-all-12"):
        print("== R-all-12: AGENT-INSTALL.md holds the rules no guard sees otherwise ==")
        guide = (CO / "AGENT-INSTALL.md").read_text(encoding="utf-8")
        flat = " ".join(guide.split())
        for rule in AGENT_RULES:
            check("R-all-12", f"verbatim: {rule[:70]}…", rule in flat)

    # ── R-all-10: uninstall, last ─────────────────────────────────────────────────────────────────────
    if A and want("R-all-10"):
        print("== R-all-10: --uninstall ==")
        A.wait_flusher()
        s = A.settings()
        s["hooks"].setdefault("Stop", []).append({"matcher": "", "hooks": [{"type": "command", "command": "/opt/mine/after-install.sh"}]})
        s["statusLine"] = {"type": "command", "command": "a-line-the-user-set-later"}
        Path(A.T["settings"]).write_text(json.dumps(s, indent=2) + "\n")
        if IS_LIN:
            (A.stub / "installed").write_text(A.crontab() + "17 3 * * * /opt/mine/nightly.sh\n")
        old = sorted(A.attempts())
        planted_secret = (old[0] if old else Path(A.T["attempts"])) / "renamed-copy.txt"
        planted_secret.parent.mkdir(parents=True, exist_ok=True)
        planted_secret.write_text("left by hand: " + A_TOK + "\n")
        n_sites = len(A.config()["sites"])
        pid = A.lock_pid()
        r = A.node("--uninstall")
        out = r.stdout
        after = json.loads(Path(A.T["settings"]).read_text())
        cmds = [x.get("command") for v in after.get("hooks", {}).values() for e in v for x in e.get("hooks", [])]
        check("R-all-10", "the reporter's entries gone by the parsed comparison, the user's kept, the foreign one added later kept",
              r.returncode == 0 and not any("fleet-reporter.js" in (c or "") for c in cmds) and "/opt/mine/after-install.sh" in cmds
              and "/opt/mine/guard.sh" in cmds and "echo welcome" in cmds, json.dumps(after) + tail(r))
        check("R-all-10", "the statusLine the user set later kept; the to-do key this install set removed, the user's env kept",
              after.get("statusLine", {}).get("command") == "a-line-the-user-set-later" and after.get("env") == {"SEAT_OWN": "1"}, json.dumps(after))
        i_cfg, i_stop = out.find("── step `uninstall: config`"), out.find("── step `uninstall: flusher`")
        check("R-all-10", "the config removed before the flusher is stopped, as a directory", 0 <= i_cfg < i_stop and not Path(A.T["configDir"]).exists(), tail(r))
        check("R-all-10", "the spool and the artifact removed, the flusher stopped",
              not Path(A.T["spool"]).exists() and not Path(A.T["artifactDir"]).exists() and (pid is None or not pid_alive(pid)), tail(r))
        if IS_LIN:
            tab = A.crontab()
            check("R-all-10", "the crontab holds the foreign line and no block; the flock file is gone",
                  "/opt/mine/nightly.sh" in tab and "fleet-reporter-flusher" not in tab and not Path(A.T["flock"]).exists(), tab)
        if IS_MAC:
            check("R-all-10", "bootout called and the plist file absent", "launchctl bootout" in (A.stub / "launchctl.log").read_text() and not Path(A.T["plist"]).exists())
        left = [p for d in A.attempts() for p in d.rglob("*") if p.is_file() and any(t in p.read_text(errors="replace") for t in SECRETS)]
        mine = A.attempts()[-1] if A.attempts() else None
        check("R-all-10", "every config backup and token file gone from every attempt directory — the one planted under another "
              "name too — while this run's settings and crontab/plist backups remain",
              left == [] and not planted_secret.exists() and mine is not None and (mine / "settings.json").exists()
              and (not IS_LIN or (mine / "crontab.txt").exists()), f"{left} {mine and sorted(p.name for p in mine.iterdir())}")
        check("R-all-10", "one revoke-prefix line per configured site, prefixes only, printed before anything is removed",
              out.count("revoke prefix `mzn_") == n_sites and A_TOK not in out and 0 <= out.rfind("revoke prefix `mzn_") < out.find("── step `uninstall: hooks`"), tail(r))
finally:
    reap()

# ── what the run leaves behind ───────────────────────────────────────────────────────────────────────
print("== the tokens in every output, and what this run leaves behind ==")
check("R-all-5", "no output of any run printed a seat token or the seat's kanban credential",
      not any(t in o for o in OUTPUTS for t in SECRETS))
left = flushers_of([Path(s.T["js"]) for s in CRON_SEATS])
check("cleanup", "every flusher this run started is stopped", left == [], str(left))
check("cleanup", "the real crontab is what it was before the run (read with `crontab -l` only)", real_crontab_md5() == REAL_BEFORE)
shutil.rmtree(TMP, ignore_errors=True)
print(f"\ninstall.selftest on {OS}: ids measured: {', '.join(sorted(seen))}")
if fails:
    print(f"install.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print("install.selftest: all checks passed")

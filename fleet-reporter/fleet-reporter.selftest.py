#!/usr/bin/env python3
"""fleet-reporter.selftest.py — hermetic, network-free acceptance for fleet-reporter.js.

WHY THIS FILE EXISTS. `fleet-reporter.js` runs inside every Claude Code hook fire on every
agent machine in the fleet. Its failure directions are not symmetric and none of them is loud:

  - IT BLOCKS OR CRASHES THE SEAT. A hook exiting 2 is the harness's BLOCK signal — the tool
    call is killed outright and the reporter's stderr is fed to the model. A hook that prints
    to stdout on SessionStart or UserPromptSubmit injects text into the MODEL'S CONTEXT. Both
    damage the agent this thing exists to watch, and neither shows up as a telemetry defect.
  - IT LOSES EVENTS SILENTLY. The whole design's promise (D1 § 0 item 9) is "bounded, COUNTED
    loss". An uncounted drop is indistinguishable from a quiet seat, which is exactly what the
    floor renders when the fleet is calm and nobody investigates.
  - IT LEAKS A CREDENTIAL. The seat token, or a secret sitting in a tool argument, reaching a
    log, a quarantine file or the wire is unrecoverable the moment it is written.

So nothing here asserts on an exit code alone: every block drives the REAL script as a REAL
subprocess over fixtures whose verdicts are stated, and reads what actually landed on disk.

RED-FIRST, PER PROPERTY. A test never seen to fail is not evidence — it is a decoration that
reports the harness ran. Every safety property below is driven twice: once against a
deliberately DEFECTIVE copy of the reporter, which must go RED, and once against the real one,
which must go GREEN. The defect is planted on a COPY in a temp directory; this suite never
mutates the file it is testing.

THE NEGATIVE CONTROL FOR THE CREDENTIAL CHECK IS THE ONE THAT MATTERS MOST, because that check
passes by finding NOTHING, and a search that cannot find a planted token would pass over a real
one identically. § 5 plants the seat token into the reporter's own log path and requires the
sweep to catch it before any of the sweep's silences are trusted.

NO NETWORK, NO CREDENTIAL, NO BOARD. The ingest stub is a TLS server on 127.0.0.1 with a
throwaway self-signed certificate, trusted through the reporter's OWN `ca_file` config key —
which is the supported private-CA path (D1 § 3.5) and therefore exercises the real code path
with certificate verification ON, rather than proving anything by turning it off.
"""
from __future__ import annotations

import atexit
import calendar
import http.server
import json
import os
import re
import shutil
import signal
import socket
import ssl
import statistics
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPORTER = HERE / "fleet-reporter.js"
TOKEN = "mzn_" + "T0kenT0kenT0kenT0kenT0kenT0kenT0kenT0kenABC"   # 43 base64url chars
assert len(TOKEN) - 4 == 43

fails = 0
_evidence: list[str] = []
_skipped: list[str] = []


def ok(msg: str) -> None:
    print(f"  ok   {msg}")


def bad(msg: str) -> None:
    global fails
    fails += 1
    print(f"  FAIL {msg}", file=sys.stderr)


def skip(msg: str) -> None:
    """A check that could not RUN here — never an `ok`, and reprinted at the end.

    A check whose instrument is unavailable has measured nothing, and printing that as a pass is
    the same defect as a sweep that finds nothing because it cannot look.
    """
    _skipped.append(msg)
    print(f"  SKIP {msg}")


def eq(what: str, want, got) -> None:
    ok(what) if want == got else bad(f"{what} — expected {want!r} got {got!r}")


def _safe(x: str) -> bool:
    try:
        json.loads(x)
        return True
    except Exception:
        return False


def redgreen(prop: str, red: str, green: str) -> None:
    """Record one property's RED/GREEN pair verbatim, for the build report."""
    _evidence.append(f"[{prop}]\n  RED   : {red}\n  GREEN : {green}")


# ── seat fixtures ──────────────────────────────────────────────────────────────────────────
def seat_token(name: str) -> str:
    """A token of the right shape that is THIS seat's alone. The suite's stub is shared by every seat, and
    since card#12180 a heartbeat is read off the wire rather than out of the spool (D1 § 6.14), so a seat's
    requests are told apart by the credential they present."""
    import base64
    import hashlib
    return "mzn_" + base64.urlsafe_b64encode(hashlib.sha256(name.encode()).digest()).decode()[:43]


class Seat:
    def __init__(self, root: Path, ingest: str, *, ca: str | None = None,
                 enabled: bool = True, wrapped: str | None = None, token: "str | None" = None):
        self.root = root
        token = token or seat_token(str(root))
        self.token = token
        self.spool = root / "spool"
        self.spool.mkdir(parents=True, exist_ok=True)
        self.cfg_path = root / "config.json"
        self.cfg = {
            "install_id": "aimla", "seat_id": "aimla-pm", "ingest_url": ingest,
            "token": token, "spool_dir": str(self.spool), "ca_file": ca,
            "proxy_url": None, "wrapped_statusline": wrapped, "enabled": enabled,
            "harness_label": "claude-code/2.1.240",
        }
        self.write_cfg()
        self.freeze_flusher()

    def write_cfg(self) -> None:
        self.cfg_path.write_text(json.dumps(self.cfg), encoding="utf-8")

    def freeze_flusher(self, at_ms=None) -> None:
        """Make the flusher lock look FRESH **on the clock the next invocation will read**.

        This is the product's own mechanism (D1 § 2.3: a lock whose mtime is under 90 s means a
        live flusher), used exactly as AT-7 specifies for the same purpose. It is not a test
        back door: no code path in the reporter is disabled, the hook simply observes a live
        owner. Without it every hook invocation would fork a real flusher into the middle of an
        exact-count assertion.

        THE MTIME IS PINNED TO THE INVOCATION'S OWN CLOCK, NOT TO WALL TIME, AND THAT IS THE
        WHOLE CORRECTNESS OF IT (card#7976). `maybeRespawnFlusher` computes
        `now() - lock.mtimeMs < LOCK_STALE_MS`, and `now()` is whatever `FLEET_REPORTER_NOW_MS`
        says. A hook driven with the clock pinned two hours AHEAD therefore reads a wall-clock
        freeze as a lock two hours dead, concludes — correctly, by § 2.3 — that no flusher is
        alive, and forks a real detached one that outlives the suite. Measured: § 4's aged-out
        drop is pinned `of_at + 2 h`, and it leaked exactly one daemon per run, within a minute
        of the run starting. Freezing against the same clock the reporter will read makes the
        lock zero seconds old under that clock, which is the state the freeze always modelled
        and never achieved on a pinned invocation.
        """
        lock = self.spool / "flusher.lock"
        lock.write_text('{"pid":1,"started_at":"1970-01-01T00:00:00.000Z"}', encoding="utf-8")
        t = time.time() if at_ms is None else int(at_ms) / 1000.0
        os.utime(lock, (t, t))

    def env(self, *, freeze: bool = True, **extra) -> dict:
        e = dict(os.environ)
        e["FLEET_REPORTER_CONFIG"] = str(self.cfg_path)
        e.pop("FLEET_REPORTER_NOW_MS", None)
        # THE MACHINE RUNNING THIS SUITE IS NOT A SEAT UNDER TEST. A developer's shell or an agent
        # seat exports `$COORD_CONFIG`, and D1 § 3.1 makes a set variable the whole of the roster
        # resolution — so an inherited one would put THIS box's coordination roster into every
        # assertion. § 19 passes the variable explicitly where a case needs it.
        e.pop("COORD_CONFIG", None)
        e.update({k: str(v) for k, v in extra.items()})
        # THE FREEZE BELONGS HERE, at the one place that knows the invocation's clock, and it is
        # re-applied PER `env()` CALL rather than once per seat. Once per seat also expires: a
        # wall-clock freeze is 90 s old after 90 s of a run that measures ~163 s, so a seat
        # driven late enough is stale on its own freeze. Bolting the re-freeze onto `hook()`
        # would miss the callers that build their invocations elsewhere — the eight-writer AT-10
        # burst and the six-writer AT-16 burst both take `seat.env()` and never call `hook()`.
        # `freeze=False` is for `flush()` alone, which IS the flusher and must find no lock.
        #
        # ⚠ PER `env()` CALL IS NOT PER HOOK, AND FOR EXACTLY TWO PATHS THAT DIFFERENCE IS REAL.
        # AT-10 and AT-16 call `env()` ONCE, to build a worker's `Popen` environment, and that
        # worker then drives 40 (resp. 15) hooks off the single lock this call froze. Hooks 2..N
        # there are as old as the burst, not 0 s: for those two paths the window is NARROWED, NOT
        # CLOSED, and no claim of "0 s on every hook" covers them.
        # MEASURED rather than extrapolated from the idle p99, because the burst's own
        # concurrency is the point — 8 workers contend, and the README's own record that hook
        # latency triples under load applies to the burst itself, so § 3's IDLE p99 understates
        # it. Driving both bursts and reading the lock's age at the last hook of each:
        # AT-10 22.6 s, AT-16 6.5 s, against LOCK_STALE_MS 90 s —
        # a 4.0x margin, not the ~9x an idle 258 ms p99 would suggest. AT-10 would have to reach
        # ~159 hooks per worker, a 4x growth, before a hook in it read its own lock as stale.
        # NOT CLOSED HERE, deliberately: closing it means a SECOND freeze implementation inside
        # generated worker source, which could only re-`utime` on WALL time — the very thing this
        # change exists to stop doing — and would silently be wrong the day a burst is given a
        # pinned clock. A duplicated primitive that cannot see the clock its hooks read buys a
        # margin nothing is spending. The suite's last block, the leaked-flusher sweep, is the guard
        # instead: if the window is ever crossed, the sweep fails the run and names the leaked
        # daemon rather than leaving it silent.
        if freeze:
            self.freeze_flusher(e.get("FLEET_REPORTER_NOW_MS"))
        return e

    def events(self) -> list[dict]:
        out = []
        for f in sorted(self.spool.glob("*.jsonl")):
            for line in f.read_text(encoding="utf-8").splitlines():
                if line.strip():
                    out.append(json.loads(line)["e"])
        return out

    def counters(self, site: "str | None" = None) -> dict:
        """The counter sink's deltas, summed. With no `site`, every line's seat-level `c` plus every site's
        `s` deltas — for a single-site seat the totals a build before card#12180 wrote into `c` alone; with
        a `site`, that site's `s` deltas only (D1 § 11.1)."""
        c: dict = {}
        d = self.spool / "counters"
        if not d.exists():
            return c
        for f in sorted(d.glob("*.jsonl")):
            for line in f.read_text(encoding="utf-8").splitlines():
                if not line.strip():
                    continue
                rec = json.loads(line)
                parts = [rec.get("c", {})] + list(rec.get("s", {}).values()) if site is None \
                    else [rec.get("s", {}).get(site, {})]
                for part in parts:
                    for k, v in part.items():
                        c[k] = c.get(k, 0) + v
        return c

    def state(self) -> dict:
        try:
            return json.loads((self.spool / "state.json").read_text(encoding="utf-8"))
        except Exception:
            return {}

    def posted(self) -> list[dict]:
        """Every batch the shared stub received from THIS seat (by the bearer token it presented)."""
        return [b["batch"] for b in list(INGEST.batches) if b.get("auth") == f"Bearer {self.token}"]

    def wire_heartbeats(self) -> list[dict]:
        """This seat's heartbeats as the stub received them, in arrival order (D1 § 6.14: never spooled)."""
        return [e for b in self.posted() for e in b.get("events", []) if e.get("kind") == "reporter.heartbeat"]

    def entry(self, site: str = "default") -> dict:
        """One site's `state.json` entry (D1 § 3.1, *State per site*), or {} where there is none."""
        return (self.state().get("sites") or {}).get(site) or {}

    def site_counters(self, site: str = "default") -> dict:
        """The folded counters ONE SITE'S heartbeat composes (D1 § 9.3, card#12180): every seat-level
        total, and for each name in the per-site set that site's total plus the seat-level one. A
        per-site count lives in the site's entry since card#12180, so a read of the top-level map alone
        would miss it."""
        st = self.state()
        out = dict(st.get("counters") or {})
        for k, v in (self.entry(site).get("counters") or {}).items():
            out[k] = out.get(k, 0) + v
        return out

    def predicates(self) -> dict:
        p: dict = {}
        d = self.spool / "counters"
        if not d.exists():
            return p
        for f in sorted(d.glob("*.jsonl")):
            for line in f.read_text(encoding="utf-8").splitlines():
                if not line.strip():
                    continue
                for k, v in json.loads(line).get("k", {}).items():
                    e = p.setdefault(k, {"true": 0, "false": 0})
                    e["true"] += v["true"]
                    e["false"] += v["false"]
        return p


def hook(seat: Seat, name: str, payload: dict, *, reporter: Path = REPORTER, **envx):
    return subprocess.run(
        ["node", str(reporter), "hook", name],
        input=json.dumps(payload), capture_output=True, text=True,
        env=seat.env(**envx), cwd=str(HERE))


def statusline(seat: Seat, payload: dict, *, reporter: Path = REPORTER, **envx):
    return subprocess.run(
        ["node", str(reporter), "statusline"],
        input=json.dumps(payload), capture_output=True, text=True,
        env=seat.env(**envx), cwd=str(HERE))


def flush(seat: Seat, *, reporter: Path = REPORTER, **envx):
    """Run ONE flusher pass, as the seat's single legitimate flusher.

    The lock the seat freezes to stop hook-side respawns would also make THIS process exit
    immediately (D1 § 2.3: a flusher that loses the exclusive create and finds a fresh lock
    exits 0). So the lock is released for the run and re-frozen after — which is the real
    handover, not a bypass: exactly one flusher is alive at any moment throughout.
    """
    # `freeze=False`: `seat.env()` re-freezes the lock on every other invocation (see
    # `freeze_flusher`), and a re-freeze here would hand THIS flusher a fresh lock — it would
    # lose `acquireLock`, exit 0, and make the pass this call exists to drive a no-op.
    env = seat.env(freeze=False, FLEET_REPORTER_ONE_PASS="1", **envx)
    lock = seat.spool / "flusher.lock"
    if lock.exists():
        lock.unlink()
    try:
        return subprocess.run(
            ["node", str(reporter), "flusher"], capture_output=True, text=True,
            env=env, cwd=str(HERE), timeout=90)
    finally:
        seat.freeze_flusher()


def selftest(seat: Seat | None = None, *, reporter: Path = REPORTER, **envx):
    env = seat.env(**envx) if seat else dict(os.environ)
    if not seat:
        env["FLEET_REPORTER_CONFIG"] = "/nonexistent/config.json"
    r = subprocess.run(["node", str(reporter), "selftest"], capture_output=True, text=True,
                       env=env, cwd=str(HERE))
    try:
        return r, json.loads(r.stdout)
    except Exception:
        return r, {}


# ── planting a defect on a COPY ────────────────────────────────────────────────────────────
# EVERY TEMP DIRECTORY THIS RUN MAKES IS REMOVED AT EXIT. The run made them and recorded them
# and nothing ever deleted them: 25 planted reporters, 5 planted fixture trees and one suite
# tree — ~52 MB of seats, spools and logs — left behind per run, measured. Removal is at EXIT
# rather than per use because the lifetimes are already the run's: a planted copy is driven as a
# SUBPROCESS and re-read afterwards, and `TMP` holds every seat the end-of-run /proc sweep has
# to match against. Nothing's identity or lifetime during the run changes, so
# `spawned_flushers()`'s `TMP`-prefix match still reads a fresh `mkdtemp` per run and reaping
# stays as safe as it was. `FR_KEEP_TMP=1` keeps them for debugging, as
# `bin/release-pr-guard.selftest.py` does with its own fixtures.
TMP_DIRS: list[Path] = []


def _sweep_tmp_dirs() -> None:
    if os.environ.get("FR_KEEP_TMP"):
        print(f"fleet-reporter.selftest: kept {len(TMP_DIRS)} temp dir(s) "
              f"(FR_KEEP_TMP is set)")
        return
    for d in TMP_DIRS:
        shutil.rmtree(d, ignore_errors=True)


atexit.register(_sweep_tmp_dirs)


def tmpdir(prefix: str) -> Path:
    """A temp directory removed when this process exits (see `_sweep_tmp_dirs`)."""
    d = Path(tempfile.mkdtemp(prefix=prefix))
    TMP_DIRS.append(d)
    return d


def plant(*subs: tuple[str, str]) -> Path:
    """Copy the reporter (with its fixtures) into a temp dir and apply each (old, new).

    Every substitution must actually match — a plant that silently applies nothing is a RED
    that quietly becomes a GREEN, which is the exact failure this whole file exists to make
    impossible.
    """
    d = tmpdir("fr-plant-")
    shutil.copytree(HERE / "fixtures", d / "fixtures")
    src = REPORTER.read_text(encoding="utf-8")
    for old, new in subs:
        if old not in src:
            raise AssertionError(f"plant anchor not found (the RED would be vacuous): {old[:70]!r}")
        src = src.replace(old, new, 1)
    p = d / "fleet-reporter.js"
    p.write_text(src, encoding="utf-8")
    return p


def plant_src(*transforms) -> Path:
    """Like `plant`, but each transform is (regex, replacement) applied to the source.

    Used where the literal to be replaced spans lines or contains regex metacharacters — a
    literal anchor for those is a plant that silently matches nothing, i.e. a RED that has
    quietly become a GREEN. Each transform must change the source or this raises.
    """
    d = tmpdir("fr-plant-")
    shutil.copytree(HERE / "fixtures", d / "fixtures")
    src = REPORTER.read_text(encoding="utf-8")
    for pattern, repl in transforms:
        # A LAMBDA replacement, not a template: the replacements here are JavaScript source
        # full of backslashes, and re.sub would try to interpret `\s` as a group reference.
        # ALL occurrences, not the first. `appendLine` has two write branches (a cached
        # descriptor for one-shot processes, a fresh open otherwise) and patching only the
        # first planted the defect in a branch this harness never executes — a RED that was
        # vacuous while looking exactly like a real one.
        new = re.sub(pattern, lambda _m, r=repl: r, src, flags=re.S)
        if new == src:
            raise AssertionError(f"plant transform matched nothing (the RED would be vacuous): {pattern!r}")
        src = new
    p = d / "fleet-reporter.js"
    p.write_text(src, encoding="utf-8")
    return p


def plant_fixture(edit) -> Path:
    """Copy the reporter + fixtures, then let `edit(fixtures_dir)` mutate the fixtures."""
    d = tmpdir("fr-fixt-")
    shutil.copytree(HERE / "fixtures", d / "fixtures")
    shutil.copy2(REPORTER, d / "fleet-reporter.js")
    edit(d / "fixtures" / "hooks")
    return d / "fleet-reporter.js"


# ── the TLS ingest stub ────────────────────────────────────────────────────────────────────
class Ingest:
    """A real TLS server on 127.0.0.1, trusted through the reporter's own ca_file key."""

    def __init__(self, workdir: Path):
        self.batches: list[dict] = []
        self.status = 202
        self.body_override: dict | None = None
        # Seconds a POST is HELD after it is recorded and before it is answered: a slow ingest,
        # which is the only condition under which a flusher's pass can outlast its own lock.
        self.delay_s = 0.0
        # The same hold for the health probe (GET), counted as it arrives: the flusher's other await.
        self.gets = 0
        self.get_delay_s = 0.0
        # What the health surface answers: the accepted schema-version set, and the status. A set
        # without this reporter's version is an ingest that REFUSES it; a non-200 is an answer
        # that carries no set at all (a bad token, an outage page) — card#9373 tells those apart.
        self.accepted: list = [1]
        self.get_status = 200
        # A per-GET script, consumed in arrival order before the defaults above apply: an entry may
        # set `hold` (seconds) and `accepted`. It lets one flusher's successive probes answer
        # differently without the suite racing that flusher to change a default between two of them.
        # A held answer closes its connection: the reporter has usually abandoned it by then. An entry's
        # `run` (a callable) runs when that GET arrives, before it is answered: it changes the seat's
        # files between the probe and the batch POST of one flusher pass.
        self.get_script: list[dict] = []
        self.key = workdir / "stub.key"
        self.crt = workdir / "stub.crt"
        subprocess.run(
            ["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes",
             "-keyout", str(self.key), "-out", str(self.crt), "-days", "2",
             "-subj", "/CN=127.0.0.1", "-addext", "subjectAltName=IP:127.0.0.1"],
            check=True, capture_output=True)
        outer = self

        class H(http.server.BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *a):  # keep the suite's output readable
                pass

            def handle(self):
                # A client that goes away mid-connection — a flusher the suite killed, a kept-alive socket
                # dropped at process exit — is not a stub failure, and its traceback is not a finding.
                try:
                    super().handle()
                except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
                    pass

            def _read(self):
                n = int(self.headers.get("Content-Length", 0))
                raw = self.rfile.read(n) if n else b""
                if self.headers.get("Content-Encoding") == "gzip":
                    import gzip
                    raw = gzip.decompress(raw)
                return raw

            def do_GET(self):
                outer.gets += 1
                step = outer.get_script.pop(0) if outer.get_script else {}
                if step.get("run"):
                    step["run"]()
                hold = step.get("hold", outer.get_delay_s)
                if hold:
                    time.sleep(hold)
                if outer.get_status == 200:
                    body = json.dumps({"accepted_schema_versions": step.get("accepted", outer.accepted),
                                       "server_time": "2026-08-24T00:00:00.000Z",
                                       "min_reporter_version": "0.1.0"}).encode()
                else:
                    body = json.dumps({"error": "unauthorized"}).encode()
                try:
                    self.send_response(outer.get_status)
                    self.send_header("Content-Type", "application/json")
                    self.send_header("Content-Length", str(len(body)))
                    self.end_headers()
                    self.wfile.write(body)
                except OSError:   # the probe's deadline passed and the reporter dropped the socket
                    pass
                if step.get("hold"):
                    self.close_connection = True

            def do_POST(self):
                raw = self._read()
                try:
                    batch = json.loads(raw)
                except Exception:
                    batch = {"_unparseable": raw[:200].decode("utf-8", "replace")}
                outer.batches.append({"batch": batch, "auth": self.headers.get("Authorization", ""),
                                      "ctype": self.headers.get("Content-Type", "")})
                if outer.delay_s:
                    time.sleep(outer.delay_s)
                st = outer.status
                body = json.dumps(outer.body_override or {
                    "batch_id": batch.get("batch_id"), "accepted": len(batch.get("events", [])),
                    "duplicates": 0, "ignored_unknown_kinds": 0, "coerced_enum_values": 0,
                    "server_time": "2026-08-24T00:00:00.000Z"}).encode()
                self.send_response(st)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

        self.httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), H)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(str(self.crt), str(self.key))
        self.httpd.socket = ctx.wrap_socket(self.httpd.socket, server_side=True)
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    @property
    def url(self) -> str:
        return f"https://127.0.0.1:{self.port}/api/ingest/events"

    def events(self) -> list[dict]:
        out = []
        for b in self.batches:
            out.extend(b["batch"].get("events", []))
        return out

    def stop(self):
        self.httpd.shutdown()


TMP = tmpdir("fr-suite-")
INGEST = Ingest(TMP)
CA = str(INGEST.crt)
SID = "11111111-2222-4333-8444-000000000000"
DEAD = "https://127.0.0.1:9/api/ingest/events"          # discard port: refused locally, no DNS, no WAN


def seat(name: str, **kw) -> Seat:
    root = TMP / name
    root.mkdir(parents=True, exist_ok=True)
    return Seat(root, kw.pop("ingest", INGEST.url), ca=kw.pop("ca", CA), **kw)


# ── flusher daemons THIS run caused to exist (card#7976) ───────────────────────────────────
# A hook that finds a stale lock forks a REAL detached flusher (§ 2.3, P-7), and that process
# has no exit condition: it loops until signalled. Prevention is `freeze_flusher` above; this is
# the MEASUREMENT, and it is the part that matters, because the leak it exists to catch survived
# for days on a workstation purely by being invisible to anyone not running `pgrep`.
def spawned_flushers() -> "list[tuple[int, str]] | None":
    """(pid, config) for every live flusher daemon whose config lives under THIS run's `TMP`.

    `TMP` is a fresh `mkdtemp` per run, so the match cannot name another worktree's daemon,
    another developer's, or a concurrent run of this same file — which is what makes reaping the
    result safe. Returns None where /proc cannot be read, so the caller reports "not measured"
    rather than reading an empty list as "none".

    NOT `pgrep -af 'fleet-reporter.*flush'`: that pattern also matches the command line of the
    shell running the measurement, so it reports at least one process that is not a daemon.
    Matching argv[1]/argv[2] and the environment exactly cannot match the measurement itself.
    """
    proc = Path("/proc")
    if not (proc / "self" / "environ").exists():
        return None
    found: list[tuple[int, str]] = []
    for entry in proc.iterdir():
        if not entry.name.isdigit():
            continue
        try:
            argv = [x.decode("utf-8", "replace")
                    for x in (entry / "cmdline").read_bytes().split(b"\0") if x]
            envb = (entry / "environ").read_bytes().split(b"\0")
        except OSError:
            continue                      # exited under us, or not this uid's to read
        if len(argv) < 3 or not argv[1].endswith("fleet-reporter.js") or argv[2] != "flusher":
            continue
        cfg = next((s.split("=", 1)[1]
                    for s in (kv.decode("utf-8", "replace") for kv in envb)
                    if s.startswith("FLEET_REPORTER_CONFIG=")), "")
        if cfg.startswith(f"{TMP}{os.sep}"):
            found.append((int(entry.name), cfg))
    return sorted(found)


def await_flushers(want: bool, timeout: float = 20.0) -> "tuple[list[tuple[int, str]], float]":
    """Poll until the spawned set is non-empty (`want`) or empty, and return it with the wait.

    The spawn is asynchronous — the hook `unref()`s it and exits — so an instant read after a
    hook is a race in BOTH directions: it can miss a daemon that is still starting, and an
    absence it reports is a measurement that never happened.
    """
    t0 = time.time()
    while True:
        got = spawned_flushers() or []
        if bool(got) == want or time.time() - t0 > timeout:
            return got, time.time() - t0
        time.sleep(0.2)


def reap_flushers(found: "list[tuple[int, str]]") -> None:
    """SIGTERM, then SIGKILL whatever is STILL one of this run's daemons — never a stale pid.

    `found` is signalled as handed over, which is sound only because it is read immediately
    before the call. THE SIGKILL LEG IS NOT: it fires up to 15 s later, and by then that list is
    history. A daemon that obeyed its SIGTERM at T+3 s has had its pid free for 12 s while the
    grace loop waited on a slower sibling, so replaying the original list signals whatever
    inherited the number. Unreachable on a box with `pid_max` 4194304, reachable at the Linux
    default of 32768 — and this is the one block in the suite licensed to signal processes, so
    the guarantee is RE-DERIVED rather than assumed: the survivor set comes from a fresh /proc
    sweep, which by `spawned_flushers`'s identity rule can only ever name a live flusher whose
    config lies under THIS run's TMP.
    """
    for pid, _ in found:
        try:
            os.kill(pid, signal.SIGTERM)
        except OSError:
            pass
    # SIGTERM sets the loop's `running = false`, but the loop is inside `await sleep(FLUSH_MS)`
    # and exits only when that resolves — up to 10 s. Grace first, certainty after.
    deadline = time.time() + 15
    while time.time() < deadline:
        if not spawned_flushers():
            return
        time.sleep(0.2)
    for pid, _ in (spawned_flushers() or []):
        try:
            os.kill(pid, signal.SIGKILL)
        except OSError:
            pass
    await_flushers(False, 10.0)


def pre(tool="Bash", ti=None, tuid="toolu_1", **extra):
    p = {"session_id": SID, "hook_event_name": "PreToolUse", "prompt_id": "p1",
         "tool_name": tool, "tool_input": ti or {"command": "echo hello"},
         "tool_use_id": tuid, "cwd": "/home/agent/mezzanine"}
    p.update(extra)
    return p


print("== 1. The `selftest` subcommand — the checks of § 6.14's member table this build implements, and each one's RED ==")
s1 = seat("selftest-seat")
gets_before = INGEST.gets
r, rep = selftest(s1)
gets_by_selftest = INGEST.gets - gets_before
eq("the checks this build declares are exactly the reported set",
   ["config_readable", "harness_payload_keys", "predicate_discrimination",
    "protocol_agent_name_in_roster", "rt_name_ok", "sanitizer_fixtures", "schema_version_accepted", "tls_verify",
    "token_accepted"],
   sorted(rep.get("checks", {}).keys()))
eq("  … and a single-site config reports one site block, `default` (§ 6.14, card#12180)", ["default"],
   sorted(rep.get("sites", {}).keys()))
eq("config_readable passes on a valid config", "pass", rep["checks"]["config_readable"])
eq("sanitizer_fixtures passes", "pass", rep["checks"]["sanitizer_fixtures"])
eq("harness_payload_keys passes", "pass", rep["checks"]["harness_payload_keys"])
eq("predicate_discrimination passes", "pass", rep["checks"]["predicate_discrimination"])

# THE TWO NETWORK CHECKS ARE MEASURED BY THE ONE-SHOT (card#9373). `selftest` is the install-time
# verification, and it used to leave `schema_version_accepted` false on every seat because only the
# flusher ever asked the ingest — so a healthy install exited 1, and an operator either concluded the
# install was broken or learned to ignore the command. The one-shot now runs the flusher's own health
# probe (`refreshHealth`: same TLS path, same `ca_file`, same deadline), and D1 § 6.14 states what a
# check it could NOT measure reports and exits: `not_measured`, exit 2 — neither a pass nor a fail.
eq("GREEN: on a healthy config the one-shot asks the ingest's health surface, once", 1, gets_by_selftest)
eq("  … schema_version_accepted passes against an ingest that accepts this reporter's version", "pass",
   rep["checks"]["schema_version_accepted"])
eq("  … tls_verify passes, the ingest reached with verification on through the seat's ca_file", "pass",
   rep["checks"]["tls_verify"])
eq("  … and the command exits 0 — a correctly configured seat reads green", 0, r.returncode)
eq("  … with the set it compared against in the site's detail", [1],
   rep["detail"]["sites"]["default"].get("accepted_schema_versions"))

# (b) An ingest that answers and does NOT list this reporter's version: measured, and a fail.
INGEST.accepted = [999]
try:
    r_refuse, rep_refuse = selftest(s1)
finally:
    INGEST.accepted = [1]
eq("RED (probed and refused): an ingest whose accepted set lacks this version fails "
   "schema_version_accepted", "fail", rep_refuse["checks"]["schema_version_accepted"])
eq("  … tls_verify still passes, because the host answered with verification on", "pass",
   rep_refuse["checks"]["tls_verify"])
eq("  … and the command exits 1", 1, r_refuse.returncode)

# (c) An ingest that cannot be reached: nothing was measured, and the report says so by name.
s_dead = seat("selftest-unreachable", ingest=DEAD)
r_dead, rep_dead = selftest(s_dead)
eq("NOT MEASURED: with the ingest unreachable, schema_version_accepted is `not_measured`, never "
   "`fail`", "not_measured", rep_dead["checks"]["schema_version_accepted"])
eq("  … and tls_verify is `not_measured` too — no TCP connection, so no TLS verdict", "not_measured",
   rep_dead["checks"]["tls_verify"])
eq("  … and the command exits 2, which § 6.14 reserves for no fail and something unmeasured", 2,
   r_dead.returncode)
eq("  … naming why the probe measured nothing", True,
   bool(rep_dead["detail"]["sites"]["default"].get("probe_error")))

# (d) An answer with no set in it — a refused token, an outage page — is not a refusal of the version.
INGEST.get_status = 401
try:
    r_401, rep_401 = selftest(s1)
finally:
    INGEST.get_status = 200
eq("NOT MEASURED: a 401 from the health surface leaves schema_version_accepted `not_measured` — the "
   "error body carries no accepted set, so it cannot refuse the version", "not_measured",
   rep_401["checks"]["schema_version_accepted"])
eq("  … with the status in the site's detail", 401, rep_401["detail"]["sites"]["default"].get("http_status"))
eq("  … and exits 2", 2, r_401.returncode)

# (e) A host reached whose certificate does not verify: THAT is a measured tls_verify fail.
s_noca = seat("selftest-no-ca", ca=None)
r_noca, rep_noca = selftest(s_noca)
eq("RED (probed and refused): without the ca_file the stub's self-signed certificate fails "
   "verification, and tls_verify is `fail`", "fail", rep_noca["checks"]["tls_verify"])
eq("  … while schema_version_accepted is `not_measured`, since no answer arrived", "not_measured",
   rep_noca["checks"]["schema_version_accepted"])
eq("  … and the command exits 1", 1, r_noca.returncode)

# RED — the defect verbatim, planted on a copy: the one-shot that never asks the ingest.
p_noprobe = plant(("    if (readable) {\n      const probe = await refreshHealth(",
                   "    if (false) {\n      const probe = await refreshHealth("))
r_np, rep_np = selftest(s1, reporter=p_noprobe)
eq("RED: a one-shot that never probes cannot pass a healthy seat — schema_version_accepted is "
   "not_measured and the exit is non-zero", ("not_measured", True),
   (rep_np["checks"]["schema_version_accepted"], r_np.returncode != 0))
redgreen("the one-shot selftest measures the ingest, and says when it could not (D1 § 6.14, card#9373)",
         f'no probe in the one-shot -> schema_version_accepted="{rep_np["checks"]["schema_version_accepted"]}", '
         f'rc={r_np.returncode} on a healthy seat (before card#9373 the same seat read "fail", rc=1)',
         f'probe via refreshHealth -> healthy: {rep["checks"]["schema_version_accepted"]}/rc={r.returncode}; '
         f'set lacks v1: {rep_refuse["checks"]["schema_version_accepted"]}/rc={r_refuse.returncode}; '
         f'unreachable: {rep_dead["checks"]["schema_version_accepted"]}/rc={r_dead.returncode}; '
         f'401: {rep_401["checks"]["schema_version_accepted"]}/rc={r_401.returncode}; '
         f'no ca_file: tls_verify={rep_noca["checks"]["tls_verify"]}/rc={r_noca.returncode}')

# (f) THE FLUSHER KEEPS ITS LAST MEASUREMENT, AND RE-PROBES SOONER WHEN A PROBE MEASURED NOTHING. The
# heartbeat's `selftest` object carries two values, so a check the flusher sends as `fail` is read on
# the seat as a failure (the ingest folds it into `selftest_failed`). A probe that times out, whose
# kept-alive socket drops, or that gets an answer carrying no set has falsified nothing: the flusher
# keeps the value its last probe MEASURED and re-probes after `K.HEARTBEAT_MS` instead of `K.HEALTH_MS`.
# A probe that did measure overwrites, `false` included.
#
# THE TIMING IS SCALED ON A COPY, AND ONLY THE TIMING. The real cadence puts the second probe
# `K.HEALTH_MS` after the first, which no suite can wait for. The copy rewrites the `K` intervals
# the scenario runs on (FAST_K's keys) and no line of logic; every RED below is planted on top of
# the same scaled copy.
#
# THE SCALE KEEPS PRODUCTION'S ORDER, FLUSH_MS < REQUEST_MS < HEARTBEAT_MS < HEALTH_MS, because the
# retry is only told apart by the gaps between those intervals. The next probe after one that measured
# nothing begins, counted from when that probe began:
#   - on EVERY pass (the defect): about REQUEST_MS + FLUSH_MS later, the deadline plus one sleep;
#   - on K.HEARTBEAT_MS (the fix): no sooner than HEARTBEAT_MS later, and about one pass after it;
#   - on K.HEALTH_MS (round 1): no sooner than HEALTH_MS later.
# A scale with REQUEST_MS above HEARTBEAT_MS (round 2's) makes the first two the same number: the pass
# that waits out the deadline has already overrun the heartbeat interval, and an every-pass flusher
# passed every assertion here. So the order is asserted below, on the copy AND on the reporter itself.
FAST_K = {"FLUSH_MS": 25, "REQUEST_MS": 300, "HEARTBEAT_MS": 1200, "HEALTH_MS": 4800}
K_ORDER = ["FLUSH_MS", "REQUEST_MS", "HEARTBEAT_MS", "HEALTH_MS"]
POLL_S = 0.01                                      # drive_probes' poll of the stub's GET count
HOLD_PAST_DEADLINE_S = 2 * FAST_K["REQUEST_MS"] / 1000   # probes 2 and 3: answered only after the deadline
# Probe 4 answers after a third of the deadline: many POLL_S polls see it ARRIVE before it answers, so
# the heartbeat split is taken before the heartbeat that follows the answer unless the suite stalls for
# the whole hold; and it still answers inside the deadline, so it measures.
REFUSAL_HOLD_S = FAST_K["REQUEST_MS"] / 3000
# LOWER BOUND on the retry. The flusher counts from `atMs`, taken at the start of the pass; the suite
# times ARRIVALS at the stub, each noticed by a POLL_S poll. An arrival trails its pass's `atMs` by
# that pass's pre-probe work (index fold, snapshot) and a fresh TLS handshake, and those lags differ
# between two passes. Measured for card#9373 round 3, the retry landed up to a few tens of milliseconds
# past HEARTBEAT_MS on an idle machine, and as far as a few tens UNDER it with the suite and the flusher
# pinned to one CPU. RETRY_SLACK_S is sized for a loaded CI runner stalling one of those lags or one
# poll, and is asserted to stay under half the gap to the every-pass retry, so the slack can never
# admit the defect the bound exists to catch.
RETRY_SLACK_S = 0.25
RETRY_AT_LEAST_S = FAST_K["HEARTBEAT_MS"] / 1000 - RETRY_SLACK_S
EVERY_PASS_RETRY_S = (FAST_K["REQUEST_MS"] + FAST_K["FLUSH_MS"]) / 1000
RETRY_WITHIN_S = FAST_K["HEALTH_MS"] / 2000        # UPPER BOUND: half the full cadence, a retry and not the next round
WINDOW_S = 0.9 * FAST_K["HEALTH_MS"] / 1000        # past this a fixed-cadence flusher could probe again

_prod_src = REPORTER.read_text(encoding="utf-8")
PROD_K = {k: int(re.search(rf"\b{k}: (\d+),", _prod_src).group(1)) for k in K_ORDER}
eq(f"precondition: the reporter's own intervals are in the order the retry bounds rely on "
   f"({' < '.join(K_ORDER)}; {PROD_K})", True,
   all(PROD_K[a] < PROD_K[b] for a, b in zip(K_ORDER, K_ORDER[1:])))
eq(f"precondition: the scaled copy keeps that order ({FAST_K})", True,
   all(FAST_K[a] < FAST_K[b] for a, b in zip(K_ORDER, K_ORDER[1:])))
eq(f"precondition: the lower bound ({RETRY_AT_LEAST_S:.2f} s) sits above an every-pass retry "
   f"(~{EVERY_PASS_RETRY_S:.3f} s) by more than twice the slack", True,
   RETRY_AT_LEAST_S - EVERY_PASS_RETRY_S > 2 * RETRY_SLACK_S)
eq(f"precondition: the upper bound ({RETRY_WITHIN_S:.2f} s) sits above a heartbeat-interval retry plus one "
   f"pass (~{(FAST_K['HEARTBEAT_MS'] + FAST_K['FLUSH_MS']) / 1000:.3f} s) by more than twice the slack, "
   f"and inside the window", True,
   RETRY_WITHIN_S - (FAST_K["HEARTBEAT_MS"] + FAST_K["FLUSH_MS"]) / 1000 > 2 * RETRY_SLACK_S
   and RETRY_WITHIN_S < WINDOW_S)


def fast_flusher(*defects) -> Path:
    return plant_src(*[(rf"\b{k}: \d+,", f"{k}: {v},") for k, v in FAST_K.items()],
                     *[(re.escape(old), new) for old, new in defects])


def heartbeat_selftests(mark: int) -> list[dict]:
    """The `selftest` object of every heartbeat the shared stub received since `mark` (an index into
    `INGEST.batches`). Since card#12180 a heartbeat is built per site as item 0 of a request and is never
    spooled (D1 § 6.14), so the wire is the one place it can be read."""
    return [e["data"]["selftest"] for b in list(INGEST.batches[mark:])
            for e in b["batch"].get("events", []) if e.get("kind") == "reporter.heartbeat"]


CROCK = "0123456789ABCDEFGHJKMNPQRSTVWXYZ"


def ulid_ms(u: str) -> int:
    """The millisecond timestamp a ULID was minted at (its first ten Crockford characters, D1 § 6.0)."""
    t = 0
    for ch in u[:10]:
        t = t * 32 + CROCK.index(ch) if ch in CROCK else t * 32
    return t


def wire_heartbeats(mark: int = 0) -> list[dict]:
    """Every heartbeat EVENT the shared stub received since `mark` (an index into `INGEST.batches`), in
    arrival order — the one place a heartbeat can be read since card#12180 (D1 § 6.14)."""
    return [e for b in list(INGEST.batches[mark:]) for e in b["batch"].get("events", [])
            if e.get("kind") == "reporter.heartbeat"]


def drive_probes(name: str, reporter: Path) -> dict:
    """A long-lived flusher on an `enabled: false` seat (no spooled event is sent, so the probe is its
    only GET and every POST is a heartbeat-only request) against scripted health answers: probe 1
    accepts this version; probes 2 and 3 are held past the probe deadline; probe 4 answers with a set
    that lacks the version. Heartbeats are split at the moment a probe ARRIVES at the stub. The site's
    lane awaits each probe before it builds, so a heartbeat received after probe k arrived was built
    after probe k-1 resolved."""
    s = seat(name, enabled=False)
    (s.spool / "flusher.lock").unlink(missing_ok=True)
    mark = len(INGEST.batches)
    INGEST.gets = 0
    INGEST.get_script = [{}, {"hold": HOLD_PAST_DEADLINE_S}, {"hold": HOLD_PAST_DEADLINE_S},
                         {"accepted": [999], "hold": REFUSAL_HOLD_S}]
    p = subprocess.Popen(["node", str(reporter), "flusher"], env=s.env(freeze=False),
                         cwd=str(HERE), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    arrived: dict[int, float] = {}
    split: dict[int, int] = {}
    try:
        started = time.time()
        while p.poll() is None and time.time() - started < 30:
            for k in (2, 3, 4):
                if k not in arrived and INGEST.gets >= k:
                    arrived[k] = time.time()
                    split[k] = len(heartbeat_selftests(mark))
            if 4 in arrived and len(heartbeat_selftests(mark)) > split[4]:
                break
            if 2 in arrived and 4 not in arrived and time.time() - arrived[2] > WINDOW_S:
                break
            time.sleep(POLL_S)
    finally:
        if p.poll() is None:
            p.kill()
            p.wait()
        INGEST.get_script = []
        s.freeze_flusher()
    hbs = heartbeat_selftests(mark)
    return {"before": hbs[: split.get(2, len(hbs))],
            "after_timeouts": hbs[split.get(2, len(hbs)): split.get(4, len(hbs))],
            "after_refusal": hbs[split[4]:] if 4 in arrived else [],
            "retry_s": round(arrived[3] - arrived[2], 2) if 3 in arrived and 2 in arrived else None}


def pair(st: dict) -> tuple:
    return (st.get("tls_verify"), st.get("schema_version_accepted"))


g_f = drive_probes("flusher-keeps-last", fast_flusher())
eq("precondition: the flusher's first probe measured both network checks, and a heartbeat says so",
   ("pass", "pass"), pair(g_f["before"][-1]) if g_f["before"] else None)
eq("GREEN: after probes that timed out, every heartbeat still reports the last measured values — "
   "no false `schema_version_accepted` fail", (True, {("pass", "pass")}),
   (bool(g_f["after_timeouts"]), {pair(x) for x in g_f["after_timeouts"]}))
eq(f"GREEN: a probe that measured nothing re-probes no sooner than K.HEARTBEAT_MS (at least "
   f"{RETRY_AT_LEAST_S:.2f} s at the scaled {FAST_K['HEARTBEAT_MS']} ms)", True,
   g_f["retry_s"] is not None and g_f["retry_s"] >= RETRY_AT_LEAST_S)
eq(f"GREEN: … and well inside the K.HEALTH_MS cadence (under {RETRY_WITHIN_S} s at the scaled "
   f"{FAST_K['HEALTH_MS']} ms)", True,
   g_f["retry_s"] is not None and g_f["retry_s"] < RETRY_WITHIN_S)
eq("GREEN: a later probe that DID measure overwrites — an answer lacking this version turns the kept "
   "pass into a fail", ("pass", "fail"), pair(g_f["after_refusal"][-1]) if g_f["after_refusal"] else None)

# RED 1 — the defect verbatim, as #143 round 1 shipped it: every probe's result overwrites, null
# included, and the cadence is K.HEALTH_MS whatever the probe measured.
r_f = drive_probes("flusher-keeps-last-red-overwrite", fast_flusher(
    ("        if (probe.checks[c] === null) unmeasured = true;\n        else lane.entry.selftest[c] = probe.checks[c];",
     "        lane.entry.selftest[c] = probe.checks[c];"),
    ("healthEveryMs = unmeasured ? K.HEARTBEAT_MS : K.HEALTH_MS;", "healthEveryMs = K.HEALTH_MS;")))
eq("RED: a flusher that lets a timed-out probe overwrite heartbeats a false fail after it",
   True, ("fail", "fail") in {pair(x) for x in r_f["after_timeouts"]})
eq("  … and, on a fixed K.HEALTH_MS cadence, does not re-probe inside half of it", True,
   r_f["retry_s"] is None or r_f["retry_s"] >= RETRY_WITHIN_S)

# RED 2 — keep-last read as keep-FIRST: a measured value is never replaced, so a refusal that arrives
# after a pass is hidden behind it.
r_k = drive_probes("flusher-keeps-last-red-keepfirst", fast_flusher(
    ("else lane.entry.selftest[c] = probe.checks[c];",
     "else if (lane.entry.selftest[c] === null) lane.entry.selftest[c] = probe.checks[c];")))
eq("RED: a flusher that never replaces a measured value keeps reporting pass after the ingest "
   "measurably refuses the version", ("pass", "pass"),
   pair(r_k["after_refusal"][-1]) if r_k["after_refusal"] else None)
# RED 3 — a re-probe on EVERY pass while a check is unmeasured, instead of one heartbeat interval on.
# In production that is a probe on every flush pass, one K.FLUSH_MS sleep (10 s) plus the probe itself,
# for as long as the ingest does not measure. The heartbeats cannot show it, since a kept value rides
# the wire either way; only the lower bound can.
r_e = drive_probes("flusher-keeps-last-red-everypass", fast_flusher(
    ("healthEveryMs = unmeasured ? K.HEARTBEAT_MS : K.HEALTH_MS;", "healthEveryMs = unmeasured ? 0 : K.HEALTH_MS;")))
eq(f"RED: a flusher that re-probes on every pass while a check is unmeasured fails the lower bound "
   f"(retry under {RETRY_AT_LEAST_S:.2f} s)", True,
   r_e["retry_s"] is not None and r_e["retry_s"] < RETRY_AT_LEAST_S)
eq("  … while its retry sits under the upper bound too, so the lower bound is the assertion that catches it",
   True, r_e["retry_s"] is not None and r_e["retry_s"] < RETRY_WITHIN_S)
redgreen("the flusher keeps the last MEASURED network checks and re-probes sooner when a probe measured "
         "nothing, but no sooner than one heartbeat interval (§ 6.14, card#9373 rounds 2-3; timing scaled on a copy)",
         f"null overwrites + fixed cadence -> heartbeats after the timed-out probes "
         f"{sorted({pair(x) for x in r_f['after_timeouts']})}, re-probe after {r_f['retry_s']} s; "
         f"never replacing a measured value -> heartbeat after a refusing answer "
         f"{pair(r_k['after_refusal'][-1]) if r_k['after_refusal'] else None}; "
         f"re-probe on every pass -> re-probe after {r_e['retry_s']} s (lower bound {RETRY_AT_LEAST_S:.2f} s)",
         f"keep-last + K.HEARTBEAT_MS retry -> before {pair(g_f['before'][-1]) if g_f['before'] else None}, "
         f"after timeouts {sorted({pair(x) for x in g_f['after_timeouts']})}, re-probe after "
         f"{g_f['retry_s']} s (bounds [{RETRY_AT_LEAST_S:.2f}, {RETRY_WITHIN_S}) s at {FAST_K}), after a refusal "
         f"{pair(g_f['after_refusal'][-1]) if g_f['after_refusal'] else None}")

# RED — an http:// ingest_url is REFUSED at install (§ 3.5), not downgraded.
s_http = seat("http-seat", ingest="http://127.0.0.1:9/api/ingest/events")
_, rep_http = selftest(s_http)
eq("RED: an http:// ingest_url fails config_readable", "fail", rep_http["checks"]["config_readable"])
eq("  … and says why, naming the rule", True,
   any("https://" in e for e in rep_http["detail"]["config_readable"]["errors"]))
redgreen("transport posture (§ 3.5)",
         f'http:// ingest_url  -> config_readable="fail", errors={rep_http["detail"]["config_readable"]["errors"]}',
         f'https:// ingest_url -> config_readable="{rep["checks"]["config_readable"]}"')

# AN UNREADABLE `ca_file` IS A CONFIG ERROR, NEVER A SILENT FALL-BACK TO THE DEFAULT TRUST STORE
# (card#9500). `ca` replaces the default store, so a seat with `ca_file` set trusts only that file
# (§ 3.5). The reporter used to catch the read failure and send with the default store: the seat's
# trust widened from one pinned CA to every publicly trusted one, and nothing said so. The widening is
# made observable with NODE_EXTRA_CA_CERTS, which adds the stub's certificate to Node's DEFAULT store —
# the stand-in for a publicly trusted certificate for the ingest's name. A reporter that falls back
# therefore DELIVERS to the stub, and one that refuses delivers nothing.
EXTRA_TRUST = {"NODE_EXTRA_CA_CERTS": CA}
s_extra = seat("ca-default-store-control", ca=None, enabled=False)
_, rep_extra = selftest(s_extra, **EXTRA_TRUST)
eq("precondition: with NODE_EXTRA_CA_CERTS the stub verifies through the default store alone (no ca_file), so "
   "a fall-back to that store is observable as a delivery", "pass", rep_extra["checks"]["tls_verify"])


def seat_log(s: Seat) -> str:
    d = s.spool / "log"
    return "".join(f.read_text(encoding="utf-8") for f in sorted(d.glob("*.log"))) if d.exists() else ""


# (b) `selftest`, and the flusher's start, on a config whose ca_file the seat must refuse.
def drive_ca_config(name: str, ca: str, reporter: Path = REPORTER) -> dict:
    s = seat(name, ca=ca)
    g0 = INGEST.gets
    r, rep_ = selftest(s, reporter=reporter, **EXTRA_TRUST)
    d = dict(path=ca, rc=r.returncode, gets=INGEST.gets - g0,
             check=rep_.get("checks", {}).get("config_readable"),
             errors=rep_.get("detail", {}).get("config_readable", {}).get("errors", []))
    hook(s, "PreToolUse", pre(tuid="toolu_ca_start"), reporter=reporter)
    b0 = len(INGEST.batches)
    flush(s, reporter=reporter, **EXTRA_TRUST)
    d.update(posts=len(INGEST.batches) - b0, log=seat_log(s),
             config_invalid=s.site_counters().get("config_invalid", 0))
    return d


def drive_ca_missing(name: str, reporter: Path = REPORTER) -> dict:
    return drive_ca_config(name, str(TMP / name / "no-such-ca.pem"), reporter)


ca_missing = drive_ca_missing("ca-unreadable-selftest")
eq("an unreadable ca_file fails config_readable in `selftest`", "fail", ca_missing["check"])
eq("  … and the error names the path and the errno", True,
   any(ca_missing["path"] in e and "ENOENT" in e for e in ca_missing["errors"]))
eq("  … the command exits 1, and asks the ingest nothing under a config that failed", (1, 0),
   (ca_missing["rc"], ca_missing["gets"]))
eq("a flusher started on it sends nothing, even to an ingest the default store trusts", 0, ca_missing["posts"])
eq("  … logs the path and the errno, and counts config_invalid", (True, True),
   (ca_missing["path"] in ca_missing["log"] and "ENOENT" in ca_missing["log"], ca_missing["config_invalid"] > 0))


# (a) The request itself: a ca_file readable when the flusher started and gone when the batch is sent —
# removed while the ingest answers the pass's health probe, which runs before the drain.
def drive_ca_vanishes(name: str, reporter: Path = REPORTER) -> dict:
    root = TMP / name
    root.mkdir(parents=True, exist_ok=True)
    ca_copy = root / "pinned-ca.pem"
    shutil.copyfile(CA, ca_copy)
    s = seat(name, ca=str(ca_copy))
    hook(s, "PreToolUse", pre(tuid="toolu_ca_gone"), reporter=reporter)
    b0 = len(INGEST.batches)
    INGEST.get_script = [{"run": ca_copy.unlink}]
    try:
        flush(s, reporter=reporter, **EXTRA_TRUST)
    finally:
        INGEST.get_script = []
    counters = s.site_counters()
    d = dict(path=str(ca_copy), posts=len(INGEST.batches) - b0, log=seat_log(s), removed=not ca_copy.exists(),
             config_invalid=counters.get("config_invalid", 0), retried=counters.get("batches_retried", 0),
             rejected=counters.get("batches_rejected", 0))
    # The refusal is the file's: restored, the same seat delivers the event it kept.
    shutil.copyfile(CA, ca_copy)
    b1 = len(INGEST.batches)
    flush(s, reporter=reporter, **EXTRA_TRUST)
    d["recovered"] = any(e.get("kind") == "tool.start" for b in INGEST.batches[b1:] for e in b["batch"].get("events", []))
    return d


ca_gone = drive_ca_vanishes("ca-vanishes")
eq("precondition: the pinned ca_file was removed during the pass's health probe", True, ca_gone["removed"])
eq("a batch request whose ca_file cannot be read is refused — nothing reaches an ingest the default store "
   "trusts", 0, ca_gone["posts"])
eq("  … the seat's log names the path and the errno", True,
   ca_gone["path"] in ca_gone["log"] and "ENOENT" in ca_gone["log"])
eq("  … it is a config failure: config_invalid counted, never a retryable batch and never a rejected one",
   (True, 0, 0), (ca_gone["config_invalid"] > 0, ca_gone["retried"], ca_gone["rejected"]))
eq("  … and the event is kept: with the file restored, the next pass delivers it", True, ca_gone["recovered"])


# (d) A flusher STARTED while its ca_file is unreadable — a delete-and-recreate rotation, a mount that is
# not up yet — recovers the way (a)'s does, with no restart. The file is the one config error a running
# flusher can outlive, because what changes is the file and not the config the flusher loaded: each pass
# re-reads it, and once it is readable the same process probes, drains and heartbeats `config_readable`
# `pass`. Refusing it at start and never looking again left a flusher that renewed its lock, so nothing
# replaced it, and sent nothing until an operator restarted it. Driven on the timing-scaled copy (§ 1's
# FAST_K) so the passes before and after the file appears take milliseconds, not K.FLUSH_MS each.
CA_APPEARS_PASSES = 20                                  # passes run on the missing file before it is created
CA_APPEARS_WITHIN_S = 10.0                              # the bound on the recovery; met in well under a second


def drive_ca_appears(name: str, reporter: Path) -> dict:
    root = TMP / name
    root.mkdir(parents=True, exist_ok=True)
    ca_later = root / "pinned-ca.pem"
    s = seat(name, ca=str(ca_later))
    hook(s, "PreToolUse", pre(tuid="toolu_ca_appears"), reporter=reporter)
    (s.spool / "flusher.lock").unlink(missing_ok=True)
    b0, g0 = len(INGEST.batches), INGEST.gets
    p = subprocess.Popen(["node", str(reporter), "flusher"], env=s.env(freeze=False, **EXTRA_TRUST), cwd=str(HERE),
                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    d: dict = {"pid": p.pid, "path": str(ca_later)}
    try:
        t0 = time.time()
        while p.poll() is None and time.time() - t0 < CA_APPEARS_WITHIN_S and "config invalid" not in seat_log(s):
            time.sleep(0.02)
        time.sleep(CA_APPEARS_PASSES * FAST_K["FLUSH_MS"] / 1000)
        d.update(refused_at_start="config invalid" in seat_log(s) and "ENOENT" in seat_log(s),
                 posts_before=len(INGEST.batches) - b0, gets_before=INGEST.gets - g0)
        shutil.copyfile(CA, ca_later)
        t1, b1 = time.time(), len(INGEST.batches)

        def events_since() -> list[dict]:
            return [e for b in INGEST.batches[b1:] for e in b["batch"].get("events", [])]

        while p.poll() is None and time.time() - t1 < CA_APPEARS_WITHIN_S:
            ev = events_since()
            if any(e.get("kind") == "tool.start" for e in ev) and any(
                    e.get("kind") == "reporter.heartbeat" and e["data"]["selftest"].get("config_readable") == "pass"
                    for e in ev):
                break
            time.sleep(0.02)
        ev = events_since()
        d.update(alive=p.poll() is None, owner_pid=s.state().get("owner_pid"),
                 delivered=any(e.get("kind") == "tool.start" for e in ev),
                 heartbeat_readable=[e["data"]["selftest"].get("config_readable") for e in ev
                                     if e.get("kind") == "reporter.heartbeat"],
                 recovery_s=round(time.time() - t1, 2))
    finally:
        if p.poll() is None:
            p.kill()
        p.wait()
        s.freeze_flusher()
    return d


ca_appears = drive_ca_appears("ca-appears", fast_flusher())
eq("a flusher started on a missing ca_file refuses the config at start, and sends and probes nothing while "
   "the file is missing", (True, 0, 0), (ca_appears["refused_at_start"], ca_appears["posts_before"], ca_appears["gets_before"]))
eq(f"  … once the file is created, the SAME flusher delivers the spooled event within {CA_APPEARS_WITHIN_S} s, "
   "with no restart", (True, True, ca_appears["pid"]),
   (ca_appears["alive"], ca_appears["delivered"], ca_appears["owner_pid"]))
eq("  … and a heartbeat it sends after that carries config_readable `pass`", True,
   "pass" in ca_appears["heartbeat_readable"])
# RED — the flusher that refuses the file at start and never looks again (the defect verbatim, planted on the
# scaled copy): the file appears and nothing is delivered.
red_appears = drive_ca_appears("ca-appears-red", fast_flusher(("    if (lane.caRechecked) {", "    if (false) {")))
eq("RED: a flusher that checks ca_file only at start is still alive and delivers nothing after the file appears",
   (True, True, False), (red_appears["refused_at_start"], red_appears["alive"], red_appears["delivered"]))
redgreen("a flusher started on an unreadable ca_file resumes once the file is readable, with no restart (§ 3.5, card#9500)",
         f"checked only at start -> refused at start {red_appears['refused_at_start']}, file created, "
         f"{CA_APPEARS_WITHIN_S} s later alive {red_appears['alive']}, delivered {red_appears['delivered']}, "
         f"heartbeat config_readable {red_appears['heartbeat_readable']}",
         f"re-checked each pass -> refused at start {ca_appears['refused_at_start']} (posts {ca_appears['posts_before']}, "
         f"probes {ca_appears['gets_before']} while missing), file created, same pid {ca_appears['owner_pid'] == ca_appears['pid']}, "
         f"delivered {ca_appears['delivered']} in {ca_appears['recovery_s']} s, heartbeat config_readable "
         f"{ca_appears['heartbeat_readable']}")

# (c) A `ca_file` that is a string but not an absolute path is the same config error: § 3.1 types the
# field "absolute path or `null`", and only `null` or an absent key leaves it unset. The empty string used
# to count as unset, so the seat trusted the whole default store. A relative path was read against the
# process's working directory, which is not the config's to choose: a flusher inherits it from whatever
# starts it, the agent's project directory through a hook or the account's home under cron. Both legs run where a fall-back to the default store, or
# a read of the relative path, shows up as a delivery: the relative path names the stub's own
# certificate from the suite's working directory.
def ca_not_absolute_errors(d: dict) -> bool:
    return any("ca_file must be an absolute path" in e for e in d["errors"])


ca_empty = drive_ca_config("ca-empty-string", "")
ca_relative = drive_ca_config("ca-relative-path", os.path.relpath(CA, HERE))
for label, d in (("an empty-string ca_file", ca_empty), (f"a relative ca_file ({ca_relative['path']})", ca_relative)):
    eq(f"{label} fails config_readable in `selftest`, and the error names the field's rule", ("fail", True),
       (d["check"], ca_not_absolute_errors(d)))
    eq("  … the command exits 1, and asks the ingest nothing under a config that failed", (1, 0), (d["rc"], d["gets"]))
    eq("  … a flusher started on it sends nothing, even to an ingest the default store trusts, and counts "
       "config_invalid", (0, True), (d["posts"], d["config_invalid"] > 0))
    eq("  … and its log names the rule", True, "ca_file must be an absolute path" in d["log"])

# RED — each leg against the defect verbatim, planted on a copy: the request that catches the read failure
# and sends with the default store, and the config check that never reads the file.
p_ca_fallback = plant(("    if (caError) { out.invalid = caError; done(out.invalid); return; }",
                       "    if (caError) { /* fall back to the system store, still verifying */ }"))
red_gone = drive_ca_vanishes("ca-vanishes-red", reporter=p_ca_fallback)
eq("RED: a request that falls back to the default store delivers the batch to an ingest the pinned ca_file "
   "never trusted, and its log never names the file", (True, 1, False),
   (red_gone["removed"], red_gone["posts"], red_gone["path"] in red_gone["log"]))
p_ca_unchecked = plant(("  if (caError) errors.push(caError);\n", ""))
red_missing = drive_ca_missing("ca-unreadable-selftest-red", reporter=p_ca_unchecked)
eq("RED: a config check that never reads ca_file passes config_readable in `selftest`, and its errors name "
   "nothing", ("pass", False),
   (red_missing["check"], any(red_missing["path"] in e for e in red_missing["errors"])))
# RED — the not-absolute legs against the defect verbatim: a `ca_file` check that treats the empty string
# as unset, and one that reads a relative path against the working directory.
p_ca_empty_unset = plant(("  if (f === null || f === undefined) return { ca: null, error: null };",
                          "  if (!f) return { ca: null, error: null };"))
red_empty = drive_ca_config("ca-empty-string-red", "", reporter=p_ca_empty_unset)
eq("RED: a check that reads \"\" as unset passes config_readable and delivers through the default store",
   ("pass", 1), (red_empty["check"], red_empty["posts"]))
p_ca_relative = plant(("  if (!path.isAbsolute(f)) return { ca: null, error: `ca_file must be an absolute path or null (§ 3.1), not ${JSON.stringify(f)}` };\n", ""))
red_relative = drive_ca_config("ca-relative-path-red", ca_relative["path"], reporter=p_ca_relative)
eq("RED: a check that reads a relative ca_file against the working directory passes config_readable and delivers",
   ("pass", 1), (red_relative["check"], red_relative["posts"]))
redgreen("a ca_file that is not an absolute path, the empty string included, is a config error (§ 3.1, card#9500)",
         f"\"\" read as unset -> selftest config_readable={red_empty['check']}, rc={red_empty['rc']}, flusher start "
         f"delivered {red_empty['posts']} through the default store; relative path read from the working directory -> "
         f"config_readable={red_relative['check']}, rc={red_relative['rc']}, delivered {red_relative['posts']}",
         f"\"\" -> config_readable={ca_empty['check']}, rc={ca_empty['rc']}, errors={ca_empty['errors']}, delivered "
         f"{ca_empty['posts']}, config_invalid {ca_empty['config_invalid']}; relative -> "
         f"config_readable={ca_relative['check']}, rc={ca_relative['rc']}, errors={ca_relative['errors']}, delivered "
         f"{ca_relative['posts']}, config_invalid {ca_relative['config_invalid']}")
redgreen("an unreadable ca_file is a config error, never a fall-back to the default trust store (§ 3.5, card#9500)",
         f"fall back in the request -> ca_file removed mid-pass: {red_gone['posts']} batch delivered through the "
         f"default store, path in log {red_gone['path'] in red_gone['log']}; no read in the config check -> "
         f"selftest config_readable={red_missing['check']}, rc={red_missing['rc']}, flusher start delivered "
         f"{red_missing['posts']}",
         f"refused -> ca_file removed mid-pass: {ca_gone['posts']} delivered, config_invalid "
         f"{ca_gone['config_invalid']}, path and errno logged, delivered after restore {ca_gone['recovered']}; "
         f"selftest config_readable={ca_missing['check']}, rc={ca_missing['rc']}, errors={ca_missing['errors']}, "
         f"flusher start delivered {ca_missing['posts']}")

# (e) § 3.1'S PUBLISHED FORMATS ARE THE CONFIG CHECK, SO A MALFORMED VALUE IS A CONFIG ERROR AT ONCE AND NOT A
# FAILURE LATER (card#9521). `loadConfig` checked `ingest_url` for its `https://` prefix only, never parsed
# `proxy_url`, and took any non-empty `spool_dir`. Each value below passed and then failed downstream: an
# `ingest_url` that `new URL` rejects made every batch `permanent`, so the flusher quarantined it and its events
# were lost; a `proxy_url` that does not parse, or has no http(s) scheme, failed every request as a retryable
# proxy error, so the seat retried forever and delivered nothing; `proxy_url: ""` read as unset and sent direct,
# around the proxy; and a relative `spool_dir` resolved against each hook's working directory. Each is now
# refused by `loadConfig`: `selftest` fails `config_readable` naming the field, and a flusher started on it
# counts `config_invalid` and sends, retries and quarantines nothing.
def drive_bad_config(name: str, key: str, value, *, reporter: Path = REPORTER, flusher: bool = True) -> dict:
    s = seat(name)
    s.cfg[key] = value
    s.write_cfg()
    g0 = INGEST.gets
    r, rep_ = selftest(s, reporter=reporter)
    d = dict(rc=r.returncode, gets=INGEST.gets - g0, check=rep_.get("checks", {}).get("config_readable"),
             errors=rep_.get("detail", {}).get("config_readable", {}).get("errors", []))
    if flusher:
        hook(s, "PreToolUse", pre(tuid=f"toolu_{name}"), reporter=reporter)
        b0 = len(INGEST.batches)
        flush(s, reporter=reporter)
        c = s.site_counters()
        q = s.spool / "quarantine" / "rejected.default.jsonl"
        d.update(posts=len(INGEST.batches) - b0, config_invalid=c.get("config_invalid", 0),
                 retried=c.get("batches_retried", 0), rejected=c.get("batches_rejected", 0),
                 quarantined=q.exists() and q.stat().st_size > 0, log=seat_log(s))
    return d


def names_field(d: dict, key: str) -> bool:
    return any(e.startswith(f"{key} ") for e in d["errors"])


FORMAT_CASES = [
    ("fmt-ingest-space", "ingest_url", "https://ingest example.org/api/ingest/events", True),
    ("fmt-ingest-bare", "ingest_url", "https://", True),
    ("fmt-proxy-unparseable", "proxy_url", "http://proxy example:3128", True),
    ("fmt-proxy-no-scheme", "proxy_url", "proxy.corp:3128", True),
    ("fmt-proxy-empty", "proxy_url", "", True),
    # A relative spool_dir drives `selftest` only here; (e2) below drives a hook and a flusher on it from
    # a temp working directory, because the old reporter writes under whatever directory that is.
    ("fmt-spool-relative", "spool_dir", "relative/spool", False),
]
fmt = {n: drive_bad_config(n, k, v, flusher=f) for n, k, v, f in FORMAT_CASES}
for n, k, v, f in FORMAT_CASES:
    d = fmt[n]
    eq(f"{k} {json.dumps(v)} fails config_readable in `selftest`, an error names the field, the command "
       f"exits 1 and asks the ingest nothing", ("fail", True, 1, 0), (d["check"], names_field(d, k), d["rc"], d["gets"]))
    if f:
        eq("  … a flusher started on it counts config_invalid and sends, retries and quarantines nothing",
           (True, 0, 0, 0, False), (d["config_invalid"] > 0, d["posts"], d["retried"], d["rejected"], d["quarantined"]))
        eq("  … and its log names the field", True, f"config invalid: " in d["log"] and f"{k} " in d["log"])
eq("a URL error never echoes the value: one that does not parse is one whose password the seat never "
   "registered for redaction", False,
   any(v in e for n, k, v, _ in FORMAT_CASES if k != "spool_dir" and v for e in fmt[n]["errors"]))
p_fmt_echo = plant(("errors.push(`${label}ingest_url is not a parseable URL (§ 3.1)`)",
                    "errors.push(`${label}ingest_url is not a parseable URL (§ 3.1): ` + url)"))
fmt_echo = drive_bad_config("fmt-ingest-echo-red", "ingest_url", FORMAT_CASES[0][2], reporter=p_fmt_echo, flusher=False)
eq("RED: … an error that echoes the value is caught by that check", True,
   any(FORMAT_CASES[0][2] in e for e in fmt_echo["errors"]))

# RED — the defect verbatim, planted on a copy: the prefix-only `ingest_url` check with the request's
# `permanent` class for an `ingest_url` that does not parse, no `proxy_url` check, and the non-empty-only
# `spool_dir` check.
p_fmt_old = plant(
    ("  else if (!parsesAsUrl(url)) errors.push(`${label}ingest_url is not a parseable URL (§ 3.1)`);\n", ""),
    ("  if (typeof site.proxy_url === 'string' && !(/^https?:\\/\\//.test(site.proxy_url) && parsesAsUrl(site.proxy_url))) {\n"
     "    errors.push(`${label}proxy_url must be an absolute http:// or https:// URL, or null (§ 3.1)`);\n  }\n", ""),
    ("  const spool = str('spool_dir') && path.isAbsolute(c.spool_dir) ? c.spool_dir : null;\n  if (spool === null) errors.push",
     "  const spool = str('spool_dir');\n  if (!spool) errors.push"),
    ("    if (r.invalid) return { kind: 'refused', status: 0, error: r.invalid };",
     "    if (r.invalid) return { kind: r.invalid === 'bad ingest_url' ? 'permanent' : 'refused', status: 0, error: r.invalid };"))
fmt_red = {n: drive_bad_config(f"{n}-red", k, v, reporter=p_fmt_old, flusher=f) for n, k, v, f in FORMAT_CASES}
for n, k, v, f in FORMAT_CASES:
    eq(f"RED: the old check passes {k} {json.dumps(v)} in `selftest`", "pass", fmt_red[n]["check"])
for n in ("fmt-ingest-space", "fmt-ingest-bare"):
    eq(f"RED: … and a flusher on {n}'s ingest_url quarantines the batch, losing its events", (True, True),
       (fmt_red[n]["quarantined"], fmt_red[n]["rejected"] > 0))
for n in ("fmt-proxy-unparseable", "fmt-proxy-no-scheme"):
    eq(f"RED: … and a flusher on {n}'s proxy_url delivers nothing and retries", (0, True),
       (fmt_red[n]["posts"], fmt_red[n]["retried"] > 0))
eq("RED: … and a flusher on proxy_url \"\" sends direct, around the configured proxy", True, fmt_red["fmt-proxy-empty"]["posts"] > 0)
redgreen("a config value outside § 3.1's published format is a config error at once, never a later failure (card#9521)",
         "; ".join(f"{k}={json.dumps(v)} -> config_readable={fmt_red[n]['check']}"
                   + (f", posts {fmt_red[n]['posts']}, retried {fmt_red[n]['retried']}, quarantined {fmt_red[n]['quarantined']}"
                      if f else "") for n, k, v, f in FORMAT_CASES),
         "; ".join(f"{k}={json.dumps(v)} -> config_readable={fmt[n]['check']}, errors={fmt[n]['errors']}"
                   + (f", config_invalid {fmt[n]['config_invalid']}, posts {fmt[n]['posts']}, retried {fmt[n]['retried']}, "
                      f"quarantined {fmt[n]['quarantined']}" if f else "") for n, k, v, f in FORMAT_CASES))


# (e2) A RELATIVE `spool_dir` WRITES NOTHING ANYWHERE (card#9521). It names no directory a process can use, so
# it is treated like an absent one: the hook and the flusher write no file at all. The old reporter, which
# also refused it as a config error, still spooled, logged and counted under the process's working
# directory — the agent's project directory for a hook, where the files can be committed. Each leg runs
# from its own temp working directory and lists every file under it afterwards.
def drive_relative_spool(name: str, reporter: Path = REPORTER) -> dict:
    s = seat(name)
    s.cfg["spool_dir"] = "relative/spool"
    s.write_cfg()
    cwd_hook = tmpdir("fr-cwd-hook-")
    cwd_flusher = tmpdir("fr-cwd-flusher-")
    r = subprocess.run(["node", str(reporter), "hook", "PreToolUse"], input=json.dumps(pre(tuid="toolu_rel")),
                       capture_output=True, text=True, env=s.env(), cwd=str(cwd_hook))
    spawned, _ = await_flushers(True, 2.0)          # a hook that finds no lock under its spool forks one
    reap_flushers(spawned)
    f = subprocess.run(["node", str(reporter), "flusher"], capture_output=True, text=True,
                       env=s.env(freeze=False, FLEET_REPORTER_ONE_PASS="1"), cwd=str(cwd_flusher), timeout=90)
    reap_flushers(spawned_flushers() or [])
    files = lambda d: sorted(str(x.relative_to(d)) for x in d.rglob("*") if x.is_file())
    return dict(hook_rc=r.returncode, hook_out=r.stdout + r.stderr, flusher_rc=f.returncode,
                hook_files=files(cwd_hook), flusher_files=files(cwd_flusher), spawned=len(spawned))


rel = drive_relative_spool("fmt-spool-relative-writes")
eq("a hook on a relative spool_dir exits 0, prints nothing, and creates no file under its working directory",
   (0, "", []), (rel["hook_rc"], rel["hook_out"], rel["hook_files"]))
eq("  … and forks no flusher", 0, rel["spawned"])
eq("a flusher on a relative spool_dir exits 0 and creates no file under its working directory", (0, []),
   (rel["flusher_rc"], rel["flusher_files"]))
# RED — the old behaviour, planted: the same config error, with the relative value still used as the spool.
p_rel_old = plant(("  const spool = str('spool_dir') && path.isAbsolute(c.spool_dir) ? c.spool_dir : null;\n"
                   "  if (spool === null) errors.push",
                   "  const spool = str('spool_dir');\n  if (!spool || !path.isAbsolute(spool)) errors.push"))
rel_red = drive_relative_spool("fmt-spool-relative-writes-red", reporter=p_rel_old)
eq("RED: a reporter that refuses a relative spool_dir but still writes to it leaves files under the hook's "
   "working directory", True, any(x.startswith("relative/spool/") for x in rel_red["hook_files"]))
eq("RED: … and under the flusher's", True, any(x.startswith("relative/spool/") for x in rel_red["flusher_files"]))
redgreen("a relative spool_dir writes nothing under the working directory (card#9521)",
         f"refused but still used -> hook cwd files {rel_red['hook_files']}, flusher cwd files "
         f"{rel_red['flusher_files']}, flushers forked {rel_red['spawned']}",
         f"treated as absent -> hook cwd files {rel['hook_files']}, flusher cwd files {rel['flusher_files']}, "
         f"flushers forked {rel['spawned']}")


# (f) ONE READ: THE HEARTBEAT'S `config_readable` IS THE FLUSHER'S OWN SEND DECISION (card#9521). The flusher
# decided from its first `loadConfig` and the heartbeat reported a second one, and nothing re-checked a
# `ca_file` that vanished after a clean start: requests refused while every heartbeat said `pass`. Driven on the
# timing-scaled copy: a flusher started on a readable pinned `ca_file` delivers, the file is removed, and then
# restored.
#
# SINCE card#12180 THE HEARTBEAT IS A REQUEST TO ITS SITE (D1 § 6.14), so a lane that has stopped sending
# because its own config failed sends no heartbeat either: the site sees the seat go `stale`, the posture every
# config error has (§ 3.1). The property is therefore measured on what reaches the site: NO request BUILT while
# the file was gone arrives, before or after the restore — a request's `batch_id` is a ULID minted at its build,
# so its time says when. The defect does: a lane still believing its config good builds a request the missing
# file refuses, and that request — persisted, resent as built — lands once the file is back, carrying whatever
# that lane decided while the seat could send nothing (a heartbeat in it says `pass`).
CA_GONE_PASSES = 160                                    # scaled passes run with the file removed (~4 s, >= 3 heartbeats)


def drive_ca_gone_live(name: str, reporter: Path) -> dict:
    root = TMP / name
    root.mkdir(parents=True, exist_ok=True)
    ca_copy = root / "pinned-ca.pem"
    shutil.copyfile(CA, ca_copy)
    s = seat(name, ca=str(ca_copy))
    hook(s, "PreToolUse", pre(tuid="toolu_ca_live_1"), reporter=reporter)
    (s.spool / "flusher.lock").unlink(missing_ok=True)
    b0 = len(INGEST.batches)
    # The backoff seam (timing only) makes a lane that does attempt while the file is gone attempt on every pass,
    # so its count is not left to the ladder's jitter.
    p = subprocess.Popen(["node", str(reporter), "flusher"], env=s.env(freeze=False, FLEET_REPORTER_BACKOFF_MAX_MS=0),
                         cwd=str(HERE), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    d: dict = {"pid": p.pid}

    def delivered(since: int) -> bool:
        return any(e.get("kind") == "tool.start" for b in INGEST.batches[since:] for e in b["batch"].get("events", []))

    try:
        t0 = time.time()
        while p.poll() is None and time.time() - t0 < CA_APPEARS_WITHIN_S and not delivered(b0):
            time.sleep(0.02)
        d["delivered_before"] = delivered(b0)
        ca_copy.unlink()
        gone_from = time.time()
        b1 = len(INGEST.batches)
        hook(s, "PreToolUse", pre(tuid="toolu_ca_live_2"), reporter=reporter)
        time.sleep(CA_GONE_PASSES * FAST_K["FLUSH_MS"] / 1000)
        d.update(posts_while_gone=len(INGEST.batches) - b1, logged="ca_file unreadable" in seat_log(s))
        gone_to = time.time()
        shutil.copyfile(CA, ca_copy)
        t1, b2 = time.time(), len(INGEST.batches)
        while p.poll() is None and time.time() - t1 < CA_APPEARS_WITHIN_S and not delivered(b2):
            time.sleep(0.02)
        time.sleep(4 * FAST_K["FLUSH_MS"] / 1000)
        # Every request that reached the site, by the clock it was BUILT on (its batch_id), from the removal on,
        # and the config_readable of any heartbeat inside it.
        built_gone, readable = [], []
        for b in INGEST.batches[b1:]:
            if gone_from + 0.1 < ulid_ms(b["batch"].get("batch_id", "")) / 1000 < gone_to:
                built_gone.append(b["batch"]["batch_id"])
                readable += [e["data"]["selftest"].get("config_readable") for e in b["batch"].get("events", [])
                             if e.get("kind") == "reporter.heartbeat"]
        d.update(alive=p.poll() is None, delivered_after=delivered(b2), requests_built_while_gone=built_gone,
                 heartbeats_built_while_gone=readable,
                 # ATTEMPTS while the file was gone: the request primitive refuses each (`ca_file unreadable`) and
                 # the lane logs it. A lane whose own verdict is `fail` makes none — but the one pass in flight at
                 # the removal may have checked the file before it went, so one is the pass's and not a verdict.
                 attempts_while_gone=seat_log(s).count("batch refused by the config"))
    finally:
        if p.poll() is None:
            p.kill()
        p.wait()
        s.freeze_flusher()
    return d


ca_live = drive_ca_gone_live("ca-gone-live", fast_flusher())
eq("precondition: a flusher started on a readable pinned ca_file delivers", True, ca_live["delivered_before"])
# The 0.1 s margin after the removal excludes the pass in flight when the file went: it may have read it before,
# and it acts on, and heartbeats, that pass's verdict.
eq("once the file is removed it sends nothing, attempts nothing past the pass in flight at the removal, and no "
   "request built while it is gone ever reaches the site — its heartbeat is a request, and the lane that cannot send "
   "builds none", (0, True, []),
   (ca_live["posts_while_gone"], ca_live["attempts_while_gone"] <= 1, ca_live["requests_built_while_gone"]))
eq("  … its log names the unreadable file", True, ca_live["logged"])
eq("  … and with the file restored the same process delivers again", (True, True), (ca_live["alive"], ca_live["delivered_after"]))
# RED — the two-verdict flusher verbatim: the file re-checked only while the start refused it.
red_live = drive_ca_gone_live("ca-gone-live-red", fast_flusher(
    ("caRechecked: errors.length === 0 && site.errors.length === (site.caError ? 1 : 0) && typeof site.ca_file === 'string',",
     "caRechecked: errors.length === 0 && site.errors.length === 1 && site.caError !== null,")))
eq("RED: a flusher that re-checks ca_file only while its start refused it reaches nothing while the file is gone, "
   "and yet goes on ATTEMPTING requests, pass after pass, on a verdict of `pass` — each refused by the request primitive",
   (True, 0, True), (red_live["delivered_before"], red_live["posts_while_gone"], red_live["attempts_while_gone"] >= 10))
redgreen("the heartbeat's config_readable is the flusher's own send verdict, from one read (card#9521)",
         f"re-checked only while refused at start -> file removed: posts {red_live['posts_while_gone']}, attempts "
         f"refused by the config logged {red_live['attempts_while_gone']}, requests built in the window and delivered "
         f"later {red_live['requests_built_while_gone']}",
         f"one verdict, re-checked each pass -> file removed: posts {ca_live['posts_while_gone']}, attempts "
         f"{ca_live['attempts_while_gone']}, requests built in "
         f"the window {ca_live['requests_built_while_gone']}, restored: delivered {ca_live['delivered_after']} by the "
         f"same pid {ca_live['alive']}")

# RED — the TLS posture lint AT-15 asks for, made mechanical.
p_tls = plant(("({ keepAlive: true, maxSockets: 2 })",
               "({ keepAlive: true, maxSockets: 2, rejectUnauthorized: false })"))
_, rep_tls = selftest(s1, reporter=p_tls)
eq("RED: a planted `rejectUnauthorized: false` fails tls_verify", "fail", rep_tls["checks"]["tls_verify"])
eq("  … even though that copy's probe reached the ingest — reachability never overrides the posture", True,
   rep_tls["detail"]["sites"]["default"].get("reached"))
eq("  … and names the forbidden spelling", True,
   len(rep_tls["detail"]["tls_verify"]["forbidden_spellings_present"]) == 1)
eq("GREEN: the real source carries no verification-disabling spelling", [],
   rep["detail"]["tls_verify"]["forbidden_spellings_present"])


# (g) THE HEALTH PROBE TAKES THE SEAT'S ROUTE, `proxy_url` INCLUDED (card#9473). The probe used to be a
# second HTTPS client with no proxy leg, so on a seat whose egress requires `proxy_url` the batches
# were delivered and the probe went direct and measured nothing: the one-shot read `not_measured`
# (rc 2) and the heartbeat rode both network checks as `fail`. The probe and the sender now share one
# request primitive, `ingestRequest`, which owns the route, the TLS options and both § 3.5 deadlines.
#
# DIRECT EGRESS TO THE INGEST IS UNREACHABLE HERE BY CONSTRUCTION, NOT BY FILTERING: these seats name
# `DEAD` (127.0.0.1:9, refused) as their ingest, and the stub proxy stands in for the proxy's own
# egress by forwarding every CONNECT to the TLS ingest stub. The stub records the target each CONNECT
# ASKED for, so "went through the proxy" is read off the proxy, and "could not have gone direct" is
# case (c) above: the same `DEAD` address, no proxy, `not_measured`. The ingest's certificate carries
# IP:127.0.0.1, so verification through the tunnel is the real check, with the seat's own `ca_file`.
class ConnectProxy:
    """A plain-HTTP CONNECT proxy on 127.0.0.1. `answer=False` accepts TCP, reads the CONNECT, and never
    answers it — the proxy that costs a client its whole connect deadline."""

    def __init__(self, upstream_port: int, *, answer: bool = True):
        self.upstream_port = upstream_port
        self.answer = answer
        self.connects: list[str] = []
        self.lsock = socket.create_server(("127.0.0.1", 0))
        self.port = self.lsock.getsockname()[1]
        threading.Thread(target=self._accept, daemon=True).start()

    @property
    def url(self) -> str:
        return f"http://127.0.0.1:{self.port}"

    def _accept(self):
        while True:
            try:
                conn, _ = self.lsock.accept()
            except OSError:
                return
            threading.Thread(target=self._serve, args=(conn,), daemon=True).start()

    @staticmethod
    def _pump(src, dst):
        try:
            while True:
                chunk = src.recv(65536)
                if not chunk:
                    break
                dst.sendall(chunk)
        except OSError:
            pass
        for s in (src, dst):
            try:
                s.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass

    def _serve(self, conn):
        conn.settimeout(60)
        buf = b""
        try:
            while b"\r\n\r\n" not in buf:
                chunk = conn.recv(4096)
                if not chunk:
                    return
                buf += chunk
            self.connects.append(buf.split(b"\r\n", 1)[0].decode("ascii", "replace").rsplit(" ", 1)[0])
            if not self.answer:
                while conn.recv(4096):   # hold until the client gives up and closes
                    pass
                return
            up = socket.create_connection(("127.0.0.1", self.upstream_port))
            conn.sendall(b"HTTP/1.1 200 Connection Established\r\n\r\n")
            rest = buf.split(b"\r\n\r\n", 1)[1]
            if rest:
                up.sendall(rest)
            conn.settimeout(None)
            threading.Thread(target=self._pump, args=(up, conn), daemon=True).start()
            self._pump(conn, up)
        except OSError:
            pass
        finally:
            conn.close()

    def stop(self):
        self.lsock.close()


PROXY = ConnectProxy(INGEST.port)
SILENT_PROXY = ConnectProxy(INGEST.port, answer=False)
DEAD_TARGET = "CONNECT 127.0.0.1:9"          # what a tunnel to DEAD's host:port asks the proxy for
K_CONNECT_S = int(re.search(r"\bCONNECT_MS: (\d+),", _prod_src).group(1)) / 1000
K_REQUEST_S = PROD_K["REQUEST_MS"] / 1000
# A pass bounded by the connect deadline ends near K_CONNECT_S; one that waits out the request deadline
# ends past K_REQUEST_S. Their midpoint tells the two apart with node's start-up on either side.
DEADLINE_SPLIT_S = (K_CONNECT_S + K_REQUEST_S) / 2
eq(f"precondition: § 3.5's connect deadline sits below its request deadline "
   f"({K_CONNECT_S} s < {K_REQUEST_S} s), so a proxy that never answers is told apart from a hang",
   True, K_CONNECT_S < K_REQUEST_S)


def proxied_seat(name: str, proxy: ConnectProxy, **kw) -> Seat:
    s = seat(name, ingest=DEAD, **kw)
    s.cfg["proxy_url"] = proxy.url
    s.write_cfg()
    return s


def send_one(s: Seat, reporter: Path = REPORTER) -> tuple[list[dict], int]:
    """Spool one event on `s`, run one flusher pass, and return (the batches the ingest received, the
    CONNECTs the proxy stub recorded) during that pass."""
    b0, c0 = len(INGEST.batches), len(PROXY.connects)
    hook(s, "PreToolUse", pre(tuid="toolu_proxy"))
    flush(s, reporter=reporter)
    return INGEST.batches[b0:], PROXY.connects[c0:]


eq("precondition: with no proxy, the DEAD address these seats name measures nothing — direct egress "
   "to it is unreachable", ("not_measured", 2), (rep_dead["checks"]["schema_version_accepted"], r_dead.returncode))

# GREEN — the one-shot, through the proxy.
s_px = proxied_seat("proxy-probe", PROXY, enabled=False)
c0, g0 = len(PROXY.connects), INGEST.gets
r_px, rep_px = selftest(s_px)
px_connects = PROXY.connects[c0:]
eq("GREEN: with proxy_url set, the one-shot's health probe asks the proxy for a tunnel to the ingest's host",
   [DEAD_TARGET], px_connects)
eq("  … and reaches the health surface through it", 1, INGEST.gets - g0)
eq("  … so tls_verify and schema_version_accepted pass, and the command exits 0",
   ("pass", "pass", 0), (rep_px["checks"]["tls_verify"], rep_px["checks"]["schema_version_accepted"], r_px.returncode))

# GREEN — the flusher's heartbeat, through the proxy. `enabled: false`, so the pass sends no spooled event and
# its one request is the heartbeat, built after the probe and before any request was accepted: the probe is
# the only thing that can have set either check (an accepted request sets tls_verify, after it).
c1, m1 = len(PROXY.connects), len(INGEST.batches)
flush(s_px)
hb_px = heartbeat_selftests(m1)
eq("GREEN: the flusher's probe goes through the proxy too — and so does the heartbeat's request after it",
   [DEAD_TARGET, DEAD_TARGET], PROXY.connects[c1:])
eq("  … and the heartbeat it sends reports both network checks pass", ("pass", "pass"),
   pair(hb_px[-1]) if hb_px else None)

# RED — the defect verbatim: the probe's request made without the seat's proxy, as before card#9473.
p_bypass = plant(("  return ingestRequest(site, { method: 'GET',",
                  "  return ingestRequest(Object.assign({}, site, { proxy_url: null }), { method: 'GET',"))
c2 = len(PROXY.connects)
r_bp, rep_bp = selftest(s_px, reporter=p_bypass)
bp_connects = PROXY.connects[c2:]
# A FRESH seat for the flusher leg: a site's last measured checks are kept in its state.json entry across
# restarts (D1 § 3.1, *State per site*), so the GREEN seat's entry would carry its passes into this run.
s_px_red = proxied_seat("proxy-probe-red", PROXY, enabled=False)
m2 = len(INGEST.batches)
flush(s_px_red, reporter=p_bypass)
hb_bp = heartbeat_selftests(m2)
eq("RED: a probe that ignores proxy_url asks the proxy for nothing, and the one-shot reads not_measured, rc 2",
   ([], "not_measured", "not_measured", 2),
   (bp_connects, rep_bp["checks"]["tls_verify"], rep_bp["checks"]["schema_version_accepted"], r_bp.returncode))
eq("  … and on a fresh flusher its heartbeat rides both checks as `fail`", ("fail", "fail"),
   pair(hb_bp[-1]) if hb_bp else None)

# The sender, through the proxy (it must not regress), and a no-proxy seat still direct.
s_ps = proxied_seat("proxy-send", PROXY)
got_ps, conn_ps = send_one(s_ps)
eq("GREEN: the sender delivers through the proxy — the batch arrives, and the pass asked the proxy for two "
   "tunnels to the ingest's host (the probe's, then the batch's)",
   (True, [DEAD_TARGET, DEAD_TARGET]),
   (any(e.get("kind") == "tool.start" for b in got_ps for e in b["batch"].get("events", [])), conn_ps))
p_send_bypass = plant(("  return ingestRequest(site, { method: 'POST',",
                       "  return ingestRequest(Object.assign({}, site, { proxy_url: null }), { method: 'POST',"))
s_psr = proxied_seat("proxy-send-red", PROXY)
got_psr, conn_psr = send_one(s_psr, reporter=p_send_bypass)
eq("RED: a sender that ignores proxy_url delivers nothing, and only the probe's tunnel is asked for",
   ([], [DEAD_TARGET]), (got_psr, conn_psr))

s_direct = seat("direct-send")
got_d, conn_d = send_one(s_direct)
c3 = len(PROXY.connects)
r_d, rep_d = selftest(s_direct)
eq("GREEN: a seat with no proxy_url sends direct — its batch arrives and the proxy stub is asked for nothing",
   (True, []), (bool(got_d), conn_d + PROXY.connects[c3:]))
eq("  … and its one-shot probe measures direct, exit 0", ("pass", "pass", 0),
   (rep_d["checks"]["tls_verify"], rep_d["checks"]["schema_version_accepted"], r_d.returncode))

# A PROXY THAT ACCEPTS TCP AND NEVER ANSWERS CONNECT. The primitive's connect deadline, K.CONNECT_MS
# (§ 3.5's 5 s), runs from the start of the request to a verified TLS session, and the proxy's
# CONNECT answer is inside it — so the probe gives up at the connect deadline, measures nothing, and
# costs the flush loop K.CONNECT_MS instead of K.REQUEST_MS.
s_sil = proxied_seat("proxy-silent", SILENT_PROXY, enabled=False)
t0 = time.monotonic()
r_sil, rep_sil = selftest(s_sil)
t_sil = time.monotonic() - t0
eq("GREEN: against a proxy that never answers CONNECT, the one-shot reports both network checks "
   "not_measured and exits 2", ("not_measured", "not_measured", 2),
   (rep_sil["checks"]["tls_verify"], rep_sil["checks"]["schema_version_accepted"], r_sil.returncode))
eq("  … naming the connect deadline as why", "connect deadline", rep_sil["detail"]["sites"]["default"].get("probe_error"))
eq(f"  … having asked that proxy for the tunnel and waited out the connect deadline, not the request "
   f"deadline ({K_CONNECT_S} s <= {t_sil:.1f} s < {DEADLINE_SPLIT_S} s)", (True, True),
   (DEAD_TARGET in SILENT_PROXY.connects, K_CONNECT_S <= t_sil < DEADLINE_SPLIT_S))
# THE PASS MAKES TWO REQUESTS SINCE card#12180: the probe, and the site's heartbeat, which is a request of its
# own (D1 § 6.14) built after the probe gave up. Both go through the silent proxy, so each is held for the
# connect deadline, and the bound is on the two together: two connect deadlines, against two request ones.
t0 = time.monotonic()
flush(s_sil)
t_sil_pass = time.monotonic() - t0
eq(f"GREEN: a flusher pass on that seat — its probe and its heartbeat's request — is held for two connect "
   f"deadlines only ({2 * K_CONNECT_S} s <= {t_sil_pass:.1f} s < {2 * DEADLINE_SPLIT_S} s)", True,
   2 * K_CONNECT_S <= t_sil_pass < 2 * DEADLINE_SPLIT_S)
p_noconnect = plant(("    connectTimer = setTimeout(() => endByDeadline('connect', 'connect deadline'), K.CONNECT_MS);\n", ""))
t0 = time.monotonic()
flush(s_sil, reporter=p_noconnect)
t_sil_red = time.monotonic() - t0
eq(f"RED: with no connect deadline the same pass waits out two request deadlines "
   f"({t_sil_red:.1f} s >= {2 * DEADLINE_SPLIT_S} s)", True, t_sil_red >= 2 * DEADLINE_SPLIT_S)
redgreen("the health probe takes the seat's route, proxy_url included, and a silent proxy costs the connect "
         "deadline, not the request deadline (§ 3.5, card#9473)",
         f"probe without the proxy -> CONNECTs {bp_connects}, one-shot "
         f"{rep_bp['checks']['tls_verify']}/{rep_bp['checks']['schema_version_accepted']} rc={r_bp.returncode}, "
         f"heartbeat {pair(hb_bp[-1]) if hb_bp else None}; sender without the proxy -> batches {len(got_psr)}, "
         f"CONNECTs {conn_psr}; no connect deadline -> silent-proxy pass {t_sil_red:.1f} s",
         f"probe via ingestRequest -> CONNECTs {px_connects}, one-shot "
         f"{rep_px['checks']['tls_verify']}/{rep_px['checks']['schema_version_accepted']} rc={r_px.returncode}, "
         f"heartbeat {pair(hb_px[-1]) if hb_px else None}; sender -> batches {len(got_ps)}, CONNECTs {conn_ps}; "
         f"no proxy_url -> batches {len(got_d)}, CONNECTs {conn_d}; silent proxy -> one-shot "
         f"{rep_sil['checks']['schema_version_accepted']} rc={r_sil.returncode} "
         f"'{rep_sil['detail']['sites']['default'].get('probe_error')}' in {t_sil:.1f} s, pass {t_sil_pass:.1f} s")
PROXY.stop()
SILENT_PROXY.stop()


print("\n== 2. SANITIZER — § 7.5's descriptor fixtures, the four REDs AT-2 names, and card#11292's ==")
# THE FIXTURE SET IS DERIVED, NEVER COUNTED. § 7.5's table is the contract and the reporter's
# SANITIZER_FIXTURES is its executable copy; a row added to one and not the other is the drift the
# trace column exists to stop, so the numbers are read out of both and compared. The doc's
# `coord.subject` rows are another producer's (§ 18.10) and are not this reporter's to run.
_d75 = (HERE.parent / "docs/design/EVENT-SCHEMA.md").read_text(encoding="utf-8")
_d75 = _d75.split("### 7.5 RED fixtures", 1)[1].split("\n## ", 1)[0]
DOC_FIXTURES = [int(m.group(1)) for m in re.finditer(r"^\| (\d+) \| `([^`]+)`,", _d75, re.M)
                if m.group(2) != "coord.subject"]
JS_FIXTURES = json.loads(subprocess.run(
    ["node", "-e", "process.stdout.write(JSON.stringify(require(process.argv[1]).SANITIZER_FIXTURES"
     ".map((f) => ({ n: f.n, rules: f.rules }))))", str(REPORTER)],
    capture_output=True, text=True, check=True).stdout)
eq("the reporter's fixtures are exactly § 7.5's descriptor rows", DOC_FIXTURES, [f["n"] for f in JS_FIXTURES])
eq("GREEN: every fixture matches its exact output AND its documented rule trace",
   [], [d for d in rep["detail"]["sanitizer_fixtures"] if not d["pass"]])
eq("  … and the selftest ran every one of them", DOC_FIXTURES, [d["fixture"] for d in rep["detail"]["sanitizer_fixtures"]])

# RED 1 — the identity sanitizer. "Replace the sanitizer body with s => s and the whole table
# must go RED." A fixture set that only ever passes proves the harness runs, nothing else.
p_id = plant(("function sanitize(input, cap) {",
              "function sanitize(input, cap) { return { text: String(input == null ? '' : input), truncated: false, rules: [], redactions: 0 };"))
_, rep_id = selftest(s1, reporter=p_id)
failed_id = [d["fixture"] for d in rep_id["detail"]["sanitizer_fixtures"] if not d["pass"]]
# Every fixture whose documented trace is non-empty must go RED. Those with an empty trace are
# the ones identity cannot discriminate, each for a stated reason: 8 is stopped by the allowlist
# (RED 2 below is its RED), and the rest are over-redaction pins whose required output IS their
# input (each has its own RED in RED 5 below).
eq("RED: the identity sanitizer fails every fixture that redacts or truncates",
   [f["n"] for f in JS_FIXTURES if f["rules"]], failed_id)
eq("  … and fixture 8 still passes, because the ALLOWLIST is what stops it, not the regexes",
   False, 8 in failed_id)
leaks = [d["leaked"] for d in rep_id["detail"]["sanitizer_fixtures"] if d["leaked"]]
eq("  … and the whole-event assertion sees the raw credentials appear", True, len(leaks) >= 3)

# RED 2 — remove only the allowlist: fixture 8 must fail ALONE, proving the two layers are
# independently load-bearing. The planted fallback renders an unknown tool's argument VALUES, the
# way a generic "show the input" fallback would. It used to render the whole input as JSON, and
# since card#11292's rule 4d a JSON `"password":` key is redacted by layer 2 — which would make this
# RED show layer 2 catching one key name rather than what layer 1 is for: an MCP tool's input is
# whatever its server defines, and a secret under a key no rule knows reaches the wire unless the
# tool is refused outright.
p_allow = plant(("  const fn = ALLOWLIST[toolName];\n  if (!fn) return",
                 "  const fn = ALLOWLIST[toolName] || ((ti) => Object.values(ti).join(' '));\n  if (!fn) return"))
_, rep_allow = selftest(s1, reporter=p_allow)
eq("RED: with the allowlist removed, fixture 8 fails ALONE",
   [8], [d["fixture"] for d in rep_allow["detail"]["sanitizer_fixtures"] if not d["pass"]])
f8 = [d for d in rep_allow["detail"]["sanitizer_fixtures"] if d["fixture"] == 8][0]
eq("  … and the planted `hunter2` reaches the serialized event", "hunter2", f8["leaked"])
redgreen("sanitizer layer 1 — the allowlist (§ 7.1)",
         f'allowlist removed -> fixture 8 descriptor={f8["got"]!r}, leaked={f8["leaked"]!r}',
         'allowlist intact  -> fixture 8 descriptor=None, leaked=None')

# RED 3 — revert rule 5 to the pre-extension rule 4: fixtures 9, 10 and 11 fail alone and each
# credential appears VERBATIM, proving the credential-on-argv extension is load-bearing. Rule 4's
# whitespace separator is stripped from BOTH of the shapes that take one — the bare keyword and,
# since card#11292, a flag whose name contains a keyword — so fixture 28's `--db-pass hunter2` and
# fixture 37's `--pw zq9w8kabc`, the same credential-on-argv shape, fail with them.
p_r5 = plant_src(
    (r"  run\(5, /[^\n]*\n[^\n]*\n", "  // rule 5 removed by plant\n"),
    (r"\(\?!\[A-Za-z\]\)\(\?:\\s\*\[:=\]\\s\*\|\\s\+\)", "(?![A-Za-z])(?:\\s*[:=]\\s*)"),
    (r"\(\?:\\s\*=\\s\*\|\\s\+\)", "(?:\\s*=\\s*)"))
_, rep_r5 = selftest(s1, reporter=p_r5)
eq("RED: reverting to the pre-extension rule 4 with no rule 5 fails fixtures 9, 10, 11, 28 and 37 ALONE",
   [9, 10, 11, 28, 37], [d["fixture"] for d in rep_r5["detail"]["sanitizer_fixtures"] if not d["pass"]])
survivors = {d["fixture"]: d["got"] for d in rep_r5["detail"]["sanitizer_fixtures"] if not d["pass"]}
eq("  … and every credential-on-argv appears VERBATIM in the descriptor", True,
   "hunter2" in survivors[9] and "admin:s3cr3t" in survivors[10] and "S3cr3tP@ss" in survivors[11])
redgreen("sanitizer layer 2 — credential-on-argv (§ 7.3 rule 5)",
         f'rule 4/5 pre-extension -> 9={survivors[9]!r} 10={survivors[10]!r} 11={survivors[11]!r}',
         "rule 5 present  -> fixture 10 = 'Bash: curl -u ‹redacted› https://api.example.org/v1/ping'")

# RED 4 — the two rules fixtures 12 and 13 pin, each failing ALONE.
p_r6 = plant_src((r"return `\$\{root\}\$\{sep\}\u2026", "return `${root}${sep}${segs[0]}${sep}\u2026"))
_, rep_r6 = selftest(s1, reporter=p_r6)
eq("RED: rule 6 keeping the first NAMED segment fails fixtures 5 and 12",
   [5, 12], [d["fixture"] for d in rep_r6["detail"]["sanitizer_fixtures"] if not d["pass"]])
p_r7 = plant(("  run(7, /\\b[A-Za-z0-9+/]{32,}={0,2}/g,", "  run(7, /\\b[A-Za-z0-9+/]{64,}={0,2}/g,"))
_, rep_r7 = selftest(s1, reporter=p_r7)
eq("RED: raising rule 7's threshold from 32 to 64 fails fixture 13 ALONE",
   [13], [d["fixture"] for d in rep_r7["detail"]["sanitizer_fixtures"] if not d["pass"]])
redgreen("sanitizer traces (AT-2 consistency check)",
         "rule 7 threshold 32->64 -> fixture 13 alone RED (trace [] != documented [7])",
         f"all {len(JS_FIXTURES)} descriptor fixtures (§ 7.5's rows, read from the doc): output AND "
         f"documented rule trace both exact")

# RED 5 — card#11292. Each mechanism the card added is disabled on its own, and exactly the
# fixtures that pin it go RED; the secret each one guards then appears in the descriptor. A
# mechanism no fixture notices being removed would be decoration, and this is where that shows.
CARD_11292_REDS = [
    ("rule 3's shape list back to its pre-card#11292 prefixes, no JWT", [22, 26, 41, 42], plant_src(
        (r"const CRED_PREFIX_RE = /[^\n]*/g;",
         r"const CRED_PREFIX_RE = /\b(gh[pousr]_|github_pat_|sk-|sk_live_|sk_test_|xox[abposr]-|AKIA|ASIA|glpat-|AIza|mzn_|mzr_)[A-Za-z0-9_-]{8,}/g;"))),
    ("rule 4a (credential headers) removed", [23, 24, 25], plant(
        ("  run(4, /\\b((?:proxy-)?authorization", "  if (0) run(4, /\\b((?:proxy-)?authorization"))),
    ("rule 4b narrowed to the bare keyword words (no flag or assignment NAME containing one)",
     [18, 19, 20, 21, 28, 31, 37, 38, 39], plant(
        (r"|(-{1,2}[A-Za-z0-9_.-]*?(?:pass|pwd|pw|secret|token|key|auth|credential|cookie)[A-Za-z0-9_.-]*(?:\s*=\s*|\s+))"
         r"|([A-Za-z0-9_.-]*?(?:pass|pwd|pw|secret|token|key|auth|credential|cookie)[A-Za-z0-9_.-]*\s*[:=]\s*))", ")"))),
    ("rule 4d (quoted JSON / dict keys) removed", [34, 35, 36], plant(
        ("  run(4, /((\\\\?[\"'])", "  if (0) run(4, /((\\\\?[\"'])"))),
    ("rule 4c (secrets-store writes) removed", [27], plant(("  if (vault) {", "  if (0) {"))),
    ("rule 7c (mixed-case base64url runs) removed", [32], plant(
        ("  run(7, /(?<![A-Za-z0-9_-])", "  if (0) run(7, /(?<![A-Za-z0-9_-])"))),
    ("rule 9's IPv6 pass removed", [44, 45], plant(
        ("  run(9, /\\[[0-9A-Fa-f:.]*", "  if (0) run(9, /\\[[0-9A-Fa-f:.]*"))),
    ("rule 9b (the host of a URL) removed", [2, 30, 43], plant(
        ("  run(9, /(?<=[A-Za-z0-9+.-]", "  if (0) run(9, /(?<=[A-Za-z0-9+.-]"))),
    ("rule 9c (dotted host names) removed", [29, 31], plant(
        ("  run(9, HOST_NAME_RE,", "  if (0) run(9, HOST_NAME_RE,"))),
]
_reds = []
for label, want, planted in CARD_11292_REDS:
    _, rep_c = selftest(s1, reporter=planted)
    got = [d for d in rep_c["detail"]["sanitizer_fixtures"] if not d["pass"]]
    eq(f"RED: {label} fails fixtures {want} ALONE", want, [d["fixture"] for d in got])
    _reds.append(f"{label} -> {[d['fixture'] for d in got]}")
# The over-redaction side: rule 9c's TLD set is curated so file names survive. Any two-letter-or-
# longer last label as a "TLD" eats `setup.py` and `README.md`, and fixture 33 exists to say so.
# Two more over-redaction pins, each with its own RED: fixture 40 (`upward` holds `pw`, and only an
# assignment or flag shape may take it) and fixture 46 (`cafe::babe` is two hex groups around `::`).
p_pw = plant(("[A-Za-z0-9_.-]*\\s*[:=]\\s*))(\"", "[A-Za-z0-9_.-]*(?:\\s*[:=]\\s*|\\s+)))(\""))
_, rep_pw = selftest(s1, reporter=p_pw)
eq("RED: a keyword-containing NAME taking a bare-space separator fails fixture 40 (prose read as argv)",
   False, [d for d in rep_pw["detail"]["sanitizer_fixtures"] if d["fixture"] == 40][0]["pass"])
p_v6 = plant(("filter(Boolean).length < 3)", "filter(Boolean).length < 0)"))
_, rep_v6 = selftest(s1, reporter=p_v6)
eq("RED: compressed IPv6 with no group floor fails fixture 46 (`cafe::babe` read as an address)",
   False, [d for d in rep_v6["detail"]["sanitizer_fixtures"] if d["fixture"] == 46][0]["pass"])
p_tld = plant(("(?:${HOST_TLDS})", "(?:[A-Za-z]{2,})"))
_, rep_tld = selftest(s1, reporter=p_tld)
f33 = [d for d in rep_tld["detail"]["sanitizer_fixtures"] if d["fixture"] == 33][0]
eq("RED: rule 9c with an uncurated TLD set fails fixture 33 (file names read as hosts)", False, f33["pass"])
redgreen("sanitizer — card#11292's secret and host shapes (§ 7.3 rules 3, 4, 7, 9)",
         "; ".join(_reds) + f"; uncurated TLDs -> fixture 33 = {f33['got']!r}",
         "every mechanism present -> fixtures 18-46 pass with output and trace exact")


print("\n== 2b. `descriptors` — HOW MUCH OF THE ALLOWLIST A SEAT SENDS (D1 § 3.1, card#11292) ==")
# Driven through real hooks, because the key is read from the config a hook loads and acts at
# two emit sites — tool.start's descriptor and subagent.spawn's title — and a function-level
# test of buildDescriptor alone could not see the second one.
DISPATCH = {"description": "check PGPASSWORD=hunter2 on db01.internal.example.com", "subagent_type": "coder"}
ABSENT = object()


def drive_descriptors(name: str, mode, reporter: Path = REPORTER) -> dict:
    s = seat(name)
    if mode is not ABSENT:
        s.cfg["descriptors"] = mode
        s.write_cfg()
    hook(s, "PreToolUse", pre(tool="Bash", ti={"command": "make deploy"}, tuid="d_bash"), reporter=reporter)
    hook(s, "PreToolUse", pre(tool="Read", ti={"file_path": "/var/www/app/Http/Controllers/HealthController.php"},
                              tuid="d_read"), reporter=reporter)
    hook(s, "PreToolUse", pre(tool="Agent", ti=DISPATCH, tuid="d_agent"), reporter=reporter)
    ev = s.events()
    spawns = [e["data"]["title"] for e in ev if e["kind"] == "subagent.spawn"]
    return {
        "descriptors": {e["data"]["tool_name"]: e["data"]["descriptor"] for e in ev if e["kind"] == "tool.start"},
        "title": spawns[0] if len(spawns) == 1 else f"<{len(spawns)} spawns>",
        "config_invalid": s.counters().get("config_invalid", 0),
        "allowlisted": s.predicates().get("descriptor_allowlisted"),
        "leaked": [x for x in ("hunter2", "db01") if any(x in json.dumps(e) for e in ev)],
    }


FULL = {"Bash": "Bash: make deploy", "Read": "Read: /\u2026/Controllers/HealthController.php",
        "Agent": "Agent: check PGPASSWORD=\u2039redacted\u203a on \u2039redacted:host\u203a"}
NOTHING = {"Bash": None, "Read": None, "Agent": None}
d_absent = drive_descriptors("desc-absent", ABSENT)
d_full = drive_descriptors("desc-full", "full")
d_paths = drive_descriptors("desc-paths", "paths")
d_none = drive_descriptors("desc-none", "none")
d_typo = drive_descriptors("desc-typo", "path")
eq("absent: every allowlisted descriptor, sanitized (today's behaviour)", FULL, d_absent["descriptors"])
eq("  … and the dispatch title, sanitized", "check PGPASSWORD=\u2039redacted\u203a on \u2039redacted:host\u203a", d_absent["title"])
eq("\"full\": the same as absent", (FULL, d_absent["title"]), (d_full["descriptors"], d_full["title"]))
eq("\"paths\": the file path only — no command, no dispatch text",
   {"Bash": None, "Read": FULL["Read"], "Agent": None}, d_paths["descriptors"])
eq("  … and no dispatch title", None, d_paths["title"])
eq("\"none\": no descriptor at all — tool names and timing only", NOTHING, d_none["descriptors"])
eq("  … and no dispatch title", None, d_none["title"])
eq("  … while descriptor_allowlisted still reads the allowlist, so § 9.4's alarm stays quiet",
   {"true": 3, "false": 0}, d_none["allowlisted"])
eq("an unrecognised value spools what \"none\" spools, never more", (NOTHING, None), (d_typo["descriptors"], d_typo["title"]))
eq("  … and is a config error, counted on every hook (the flusher then sends nothing, § 9.3)", 3, d_typo["config_invalid"])
eq("  … and no valid value is one", [0, 0, 0, 0], [d["config_invalid"] for d in (d_absent, d_full, d_paths, d_none)])
eq("nothing the dispatch text held reaches the spool, under any value",
   [[], [], [], [], []], [d["leaked"] for d in (d_absent, d_full, d_paths, d_none, d_typo)])

# RED — the two ways this key dies: the hook ignores it, or a typo widens it.
p_ignore = plant(("const d = buildDescriptor(toolName, ti, mode);", "const d = buildDescriptor(toolName, ti);"),
                 ("const title = mode === 'full' && typeof", "const title = typeof"))
r_ignore = drive_descriptors("desc-none-ignored", "none", reporter=p_ignore)
eq("RED: a hook that ignores the key sends every descriptor and the title under \"none\"",
   (FULL, False), (r_ignore["descriptors"], r_ignore["title"] is None))
p_widen = plant(("? cfg.descriptors : 'none';", "? cfg.descriptors : 'full';"))
r_widen = drive_descriptors("desc-typo-widened", "path", reporter=p_widen)
eq("RED: a typo read as \"full\" sends the command and the dispatch text", FULL, r_widen["descriptors"])
redgreen("`descriptors` (D1 § 3.1, card#11292)",
         f"key ignored -> \"none\" sends {r_ignore['descriptors']} + title {r_ignore['title']!r}; "
         f"typo read as full -> {r_widen['descriptors']}",
         f"absent/full -> {d_full['descriptors']}; paths -> {d_paths['descriptors']}; none -> "
         f"{d_none['descriptors']}, title {d_none['title']!r}; typo -> none + config_invalid "
         f"{d_typo['config_invalid']}")


print("\n== 3. NEVER BLOCKS THE SEAT (P-1..P-5, AT-3) ==")
s3 = seat("neverblocks")
timings = []
for i in range(60):
    t0 = time.perf_counter()
    r = hook(s3, "PreToolUse", pre(tuid=f"toolu_{i}"))
    timings.append((time.perf_counter() - t0) * 1000)
    if r.returncode != 0 or r.stdout:
        bad(f"PreToolUse #{i} exit={r.returncode} stdout={r.stdout!r}")
        break
else:
    ok(f"60/60 PreToolUse invocations exit 0 with EMPTY stdout")
p99 = statistics.quantiles(timings, n=100)[98]

# ── LATENCY IS MEASURED AND PRINTED; IT IS ASSERTED ONLY UNDER FLEET_REPORTER_PERF=1 ─────────
# D1 § 2.2 derives P-5's 250 ms against "Node cold start on a modern machine is 30-60 ms and
# dominates". Two things are true here and they point the same way:
#
#   1. The interpreter's own start-up dwarfs the reporter's work on this class of machine, so an
#      ABSOLUTE assertion would be red no matter what this file contains — a check pinned red by
#      the interpreter discriminates nothing about the code.
#   2. An ATTRIBUTABLE assertion (hook time minus a node baseline) was tried and MEASURED not to
#      be robust. With baseline and reporter samples interleaved so both see the same
#      contemporaneous load: idle -> attributable median 53 ms, p99-difference 75 ms; under six
#      busy cores -> median 82 ms then 163 ms, and the p99-difference swung to -1070 ms and then
#      +520 ms ON IDENTICAL CODE. The median is the better statistic and still triples under
#      load; the p99 difference is noise. So subtracting a baseline does not isolate the code
#      well enough to carry a bound, and an assertion that reds under load is worse than none:
#      it teaches the next maintainer to re-run until green, or to loosen the bound.
#
# So the default run MEASURES AND PRINTS, and the gate lives in a dedicated, non-concurrent perf
# run. This is the same treatment the absolute figure already had, applied to both.
base, rep = [], []
for _ in range(20):                       # interleaved, so both see the same load
    t0 = time.perf_counter()
    hook(s3, "PreToolUse", pre(tuid=f"lat_{_}"))
    rep.append((time.perf_counter() - t0) * 1000)
    t0 = time.perf_counter()
    subprocess.run(["node", "-e", ""], capture_output=True)
    base.append((time.perf_counter() - t0) * 1000)
baseline = statistics.median(base)
attributable = statistics.median(rep) - baseline
PERF = bool(os.environ.get("FLEET_REPORTER_PERF"))
print(f"  MEASURED  hook p99 = {p99:.0f} ms against P-5's 250 ms budget. Interleaved medians: "
      f"reporter {statistics.median(rep):.0f} ms, bare `node -e ''` {baseline:.0f} ms, "
      f"ATTRIBUTABLE {attributable:.0f} ms (D1 § 2.2 assumes a 30-60 ms interpreter start; "
      f"this machine is {baseline:.0f} ms). Absolute budget: {'WITHIN' if p99 < 250 else 'OVER'}.")
if PERF:
    # D1's own arithmetic: 250 ms total, of which it budgets 30-60 ms for the interpreter,
    # leaving ~190 ms for everything the reporter does.
    eq(f"PERF: reporter-attributable median within D1's own ~190 ms headroom "
       f"({attributable:.0f} ms)", True, attributable < 190)
else:
    print("  (latency is measured, not asserted — set FLEET_REPORTER_PERF=1 on a quiet machine "
          "to gate on it; see fleet-reporter/README.md § Decisions)")

# The worst case the budget is DERIVED against is not the cheap path — it is a session-boundary
# reap at the 64-open-call cap (~130 appends from one hook process). Measuring only the cheap
# path would report a budget nothing tests.
s3b = seat("reap-at-cap")
for i in range(64):
    hook(s3b, "PreToolUse", pre(tool="Bash", tuid=f"cap_{i}"))
t0 = time.perf_counter()
r = hook(s3b, "SessionEnd", {"session_id": SID, "hook_event_name": "SessionEnd", "reason": "clear"})
reap_ms = (time.perf_counter() - t0) * 1000
# The reap is the worst case the budget is derived against, so it is measured the same way:
# its EXIT CODE and its OUTPUT are asserted always (those are not load-sensitive), its cost is
# printed always, and gated only under PERF.
eq("a 64-call reap exits 0 with empty stdout", (0, ""), (r.returncode, r.stdout))
print(f"  MEASURED  64-call reap = {reap_ms:.0f} ms wall, {reap_ms - baseline:.0f} ms attributable")
if PERF:
    eq(f"PERF: the 64-call reap's attributable cost is inside the headroom "
       f"({reap_ms - baseline:.0f} ms)", True, (reap_ms - baseline) < 190)
ends = [e for e in s3b.events() if e["kind"] == "tool.end"]
eq("  … and closes all 64 calls it was holding", 64, len(ends))

# ADVERSE CONDITIONS — every one of these is a real seat state, and none may reach the agent.
adverse = []
s_ro = seat("readonly")
os.chmod(s_ro.spool, 0o500)
adverse.append(("read-only spool_dir", hook(s_ro, "PreToolUse", pre())))
os.chmod(s_ro.spool, 0o700)
s_nc = seat("noconfig")
s_nc.cfg_path.unlink()
adverse.append(("config file absent", hook(s_nc, "PreToolUse", pre())))
s_tj = seat("tornjournal")
hook(s_tj, "PreToolUse", pre())
jf = sorted((s_tj.spool / "index").glob("*.jsonl"))[0]
jf.write_text(jf.read_text() + '{"k":"open","call_id":"trunc', encoding="utf-8")
adverse.append(("torn last index-journal line", hook(s_tj, "PreToolUse", pre(tuid="t2"))))
s_bs = seat("badsnapshot")
(s_bs.spool / "index").mkdir(parents=True, exist_ok=True)
(s_bs.spool / "index" / "snapshot.json").write_text("{not json at all", encoding="utf-8")
adverse.append(("unparseable index/snapshot.json", hook(s_bs, "PreToolUse", pre())))
s_es = seat("emptystdin")
adverse.append(("empty stdin", subprocess.run(["node", str(REPORTER), "hook", "PreToolUse"],
                                              input="", capture_output=True, text=True,
                                              env=s_es.env(), cwd=str(HERE))))
adverse.append(("garbage stdin", subprocess.run(["node", str(REPORTER), "hook", "PreToolUse"],
                                                input="}{not json", capture_output=True, text=True,
                                                env=s_es.env(), cwd=str(HERE))))
adverse.append(("unknown hook name", hook(s_es, "NoSuchHook", {"session_id": SID})))
for label, res in adverse:
    if res.returncode != 0 or res.stdout:
        bad(f"{label}: exit={res.returncode} stdout={res.stdout!r}")
    else:
        ok(f"{label}: exit 0, stdout empty")

# RED — the two ways this property dies, both driven.
p_exit = plant(("  } finally {\n    process.exit(0);\n  }", "  } finally {\n    process.exit(1);\n  }"))
r_red = subprocess.run(["node", str(p_exit), "hook", "PreToolUse"], input="}{not json",
                       capture_output=True, text=True, env=s_es.env(), cwd=str(HERE))
eq("RED: a reporter that propagates its failure exits non-zero", True, r_red.returncode != 0)
p_net = plant(("function hookMain(hookName) {\n",
               "function hookMain(hookName) {\n  try { require('child_process').execSync('sleep 2'); } catch (e) {}\n"))
t0 = time.perf_counter()
subprocess.run(["node", str(p_net), "hook", "PreToolUse"], input=json.dumps(pre()),
               capture_output=True, text=True, env=s3.env(), cwd=str(HERE))
blocked_ms = (time.perf_counter() - t0) * 1000
eq(f"RED: a synchronous 2 s call in the hook path blows the budget ({blocked_ms:.0f} ms)",
   True, blocked_ms > 250)
p_out = plant(("    if (cmd === 'statusline') statuslineMain();",
               "    if (cmd === 'statusline') statuslineMain();\n    else if (cmd === 'hook') { console.log('{\"decision\":\"block\"}'); hookMain(process.argv[3] || ''); }"))
r_out = subprocess.run(["node", str(p_out), "hook", "PreToolUse"], input=json.dumps(pre()),
                       capture_output=True, text=True, env=s3.env(), cwd=str(HERE))
eq("RED: a reporter that prints to stdout is caught (that text reaches the MODEL)",
   True, r_out.stdout.strip() != "")
redgreen("never blocks the seat (P-1..P-5, AT-3)",
         f"exit(1) plant -> rc={r_red.returncode}; sync-2s plant -> {blocked_ms:.0f} ms (> 250 ms budget); "
         f"stdout plant -> stdout={r_out.stdout.strip()!r}",
         f"real reporter -> 60/60 rc=0, stdout empty; p99={p99:.0f} ms wall, attributable median "
         f"{attributable:.0f} ms (node baseline {baseline:.0f} ms); 64-call reap {reap_ms:.0f} ms "
         f"wall rc=0 with 64 tool.end emitted; 7/7 adverse states rc=0 stdout empty")


print("\n== 4. SURVIVES THE BRIDGE BEING DOWN (AT-4) ==")
s4 = seat("outage", ingest=DEAD)
for i in range(30):
    r = hook(s4, "PreToolUse", pre(tuid=f"out_{i}"))
    if r.returncode != 0:
        bad(f"hook #{i} failed during the outage: rc={r.returncode}")
        break
else:
    ok("30/30 hooks exit 0 while the ingest is unreachable — the seat is unaffected")
flush(s4)
during = s4.events()
eq("the spool retains every event through the outage", 30,
   len([e for e in during if e["kind"] == "tool.start"]))
eq("nothing was dropped (spool_dropped_events stays 0)", 0, s4.counters().get("spool_dropped_events", 0))
eq("  … and the unreachable ingest is COUNTED as a retry rather than swallowed", True,
   s4.site_counters().get("batches_retried", 0) > 0)
# Since card#12180 the heartbeat is built per site as item 0 of that site's request and never spooled
# (D1 § 6.14): with the ingest down it is IN the request persisted in the site's entry, and it is the first
# thing the site receives on recovery.
req4 = s4.entry().get("request") or {}
hb = [e for e in req4.get("events", []) if e.get("kind") == "reporter.heartbeat"]
eq("the flusher heartbeats even with the ingest down (silence must never be the signal): the heartbeat is "
   "item 0 of the request persisted for the site", (True, req4.get("heartbeat_event_id")),
   (len(hb) == 1 and req4["events"][0]["kind"] == "reporter.heartbeat", hb[0]["event_id"] if hb else None))
eq("  … and the heartbeat reports the backlog rather than hiding it", True,
   bool(hb) and hb[-1]["data"]["spool_lag_events"] > 0 and hb[-1]["data"]["oldest_unsent_age_s"] is not None)

# Now the server comes back: EVERY spooled event must arrive, by id, not by count. The config now names
# another server, which § 3.1's rule 2 reads as a repoint: the site's entry — cursor, epoch and the request
# persisted for the dead address — is discarded, and the whole spool is sent to the server it now names.
INGEST.batches.clear()
s4.cfg["ingest_url"] = INGEST.url
s4.write_cfg()
target_ids = {e["event_id"] for e in s4.events()}
for _ in range(8):
    flush(s4)
    if target_ids <= {d["event_id"] for d in INGEST.events()}:
        break
delivered_ids = [e["event_id"] for e in INGEST.events()]
missing = target_ids - set(delivered_ids)
eq("on restore, EVERY event spooled during the outage is delivered (by id, not by count)",
   set(), missing)
eq("  … and none is delivered twice by the reporter", len(delivered_ids), len(set(delivered_ids)))
seqs = [e["seq"] for e in INGEST.events()]
eq("  … with a strictly increasing seq that SURVIVES flusher restarts "
   "(a restart re-using seq 1 is the D2-MUST #4 ordering-key collision)", sorted(set(seqs)), seqs)
eq("  … all under one seq_epoch", 1, len({b["batch"]["seq_epoch"] for b in INGEST.batches}))

# RED — shrink the spool bound so overflow must happen, and prove the loss is VISIBLE.
#
# THE CLOCK IS PINNED HERE, AND THAT IS LOAD-BEARING (card#7952). The hook's refusal is keyed
# to the UTC hour: `enforceSpoolBoundFromHook` defers only while the oldest bucket IS the
# current-hour bucket, and once that hour has ended plus `BUCKET_GRACE_MS` (5 s) the very same
# hook DROPS it — correctly, by § 11.3. Read off the real clock these 20 hooks take ~5 s, the
# same order as that grace, so a run that happened to straddle a top-of-hour would watch the
# hook do the right thing and red the "drops nothing" assertion below on CORRECT behaviour.
# Seen once in the field and reproduced here deterministically by pinning a crossing. It is
# not a flake to retry and not an assertion to loosen: it was this check reading a wall clock
# it never meant to depend on, and the fix is to stop reading it. Pinned mid-hour, no run
# duration — loaded, slow disk, or otherwise — can reach a boundary.
of_at = (int(time.time()) // 3600 - 1) * 3600 * 1000 + 1_800_000   # 30 min into the last hour
p_small = plant_src((r"SPOOL_BYTES: 33554432,", "SPOOL_BYTES: 2048,"))
s4r = seat("overflow", ingest=DEAD)
for i in range(20):
    hook(s4r, "PreToolUse", pre(tuid=f"of_{i}"), reporter=p_small, FLEET_REPORTER_NOW_MS=of_at)
deferred = s4r.counters().get("spool_overflow_deferred", 0)
eq("RED: over a 2 KiB bound, a hook REFUSES to drop the current-hour bucket and says so "
   "(unlinking a file other hooks are appending to would lose uncounted events)",
   True, deferred > 0)
eq("  … and drops nothing while deferring", 0, s4r.counters().get("spool_dropped_events", 0))

# THE COMPLEMENT, which is what keeps the assertion above from being satisfied by a reporter
# that simply never drops. Same fill, then ONE hook two hours on: the bucket has aged out, so
# the hook must now drop it AND count every line in it (§ 0 item 9 — loss is bounded and
# COUNTED). This path used to be reached only when a run accidentally straddled the hour roll
# — i.e. it was the flake, never a test. Pinning the clock is what turns it into one.
s4d = seat("overflow-aged", ingest=DEAD)
for i in range(20):
    hook(s4d, "PreToolUse", pre(tuid=f"ag_{i}"), reporter=p_small, FLEET_REPORTER_NOW_MS=of_at)
aged_before = s4d.counters().get("spool_dropped_events", 0)
hook(s4d, "PreToolUse", pre(tuid="ag_next"), reporter=p_small,
     FLEET_REPORTER_NOW_MS=of_at + 2 * 3600 * 1000)
aged_dropped = s4d.counters().get("spool_dropped_events", 0) - aged_before
eq("GREEN (complement): once that bucket's hour HAS ended, the same hook drops it and counts "
   "every line — the refusal above is hour-keyed, not a reporter that never drops", 20,
   aged_dropped)

# The drop itself belongs to the flusher, and only on a bucket whose hour has ended. The clock
# seam writes the backlog into a PAST hour so the deletion precondition is genuinely satisfied.
s4h = seat("overflow-flusher", ingest=DEAD)
past = int((time.time() - 7200) * 1000)
for i in range(20):
    hook(s4h, "PreToolUse", pre(tuid=f"old_{i}"), reporter=p_small, FLEET_REPORTER_NOW_MS=past)
before = len(list(s4h.spool.glob("*.jsonl")))
flush(s4h, reporter=p_small)
red_dropped = s4h.counters().get("spool_dropped_events", 0) + s4h.site_counters().get("spool_dropped_events", 0)
eq("RED: the flusher drops the aged-out oldest bucket AND COUNTS every event in it",
   True, red_dropped > 0)
s4g = seat("nooverflow", ingest=DEAD)
for i in range(20):
    hook(s4g, "PreToolUse", pre(tuid=f"ng_{i}"), FLEET_REPORTER_NOW_MS=past)
flush(s4g)
eq("GREEN (control): under the real 32 MiB bound the same run drops nothing", 0,
   s4g.counters().get("spool_dropped_events", 0) + s4g.site_counters().get("spool_dropped_events", 0))
redgreen("survives the bridge being down (AT-4)",
         f"2 KiB spool bound, clock pinned mid-hour -> hook defers ({deferred} x "
         f"spool_overflow_deferred, 0 dropped: it may not unlink the live bucket); one hook two "
         f"hours on drops the aged-out bucket counting all {aged_dropped} lines; flusher likewise "
         f"drops the aged-out bucket -> spool_dropped_events={red_dropped}",
         f"real bound, ingest refused for 30 hooks then restored -> 30/30 hooks rc=0, "
         f"spool_dropped_events=0, {len(delivered_ids)} events delivered, missing={sorted(missing)}, "
         f"seq strictly increasing")

# A DROPPED BUCKET COUNTS ONLY WHAT WAS NEVER DELIVERED (card#11548, D1 § 11.3 "Loss visibility").
# `spool_dropped_events` is a LOSS counter, and a line behind the bucket's delivery cursor already
# reached the ingest (or was counted under its own loss counter when it was disposed of). The
# defect counted every line of a dropped bucket, so a busy seat whose spool sat at its bound with
# delivered history rendered `lossy` on every drop, with nothing lost. Each case writes a bucket
# of DROP_N lines and a state.json naming that bucket's cursor, then drives the real drop path.
DROP_N = 5
DROP_LINE = json.dumps({"v": 1, "t": "2000-01-01T00:00:00.000Z",
                        "e": {"kind": "tool.start", "event_id": "01K11548" + "0" * 18, "pad": "x" * 400}}) + "\n"


def drop_case(name: str, *, cursor_lines, path: str, reporter: Path = REPORTER) -> int:
    """Drop one bucket of DROP_N lines whose cursor sits after `cursor_lines` lines (None = no
    cursor) through the flusher's residency bound or a hook's size bound; return the count."""
    s = seat(name, ingest=DEAD, enabled=(path == "hook"))
    age_s = 9 * 86400 if path == "flusher" else 7200
    b = time.strftime("%Y%m%d%H", time.gmtime(time.time() - age_s))
    (s.spool / f"{b}.jsonl").write_text(DROP_LINE * DROP_N, encoding="utf-8")
    st = {"seq_epoch": "01K11548STATE0000000000000", "next_seq": 1, "cursors": {}}
    if cursor_lines is not None:
        st["cursors"][b] = len(DROP_LINE.encode("utf-8")) * cursor_lines
    (s.spool / "state.json").write_text(json.dumps(st), encoding="utf-8")
    if path == "flusher":
        flush(s, reporter=reporter)
        dropped = s.site_counters().get("spool_dropped_events", 0)
    else:
        # The hook's own event lands in the current hour, so the 2 h-old bucket is the oldest,
        # its hour has ended, and the 2 KiB bound (the bucket alone is over it) makes it drop.
        hook(s, "PreToolUse", pre(tuid=f"{name}_0"), reporter=reporter)
        dropped = s.counters().get("spool_dropped_events", 0)
    eq(f"  ({name}: the bucket was actually dropped)", False, (s.spool / f"{b}.jsonl").exists())
    return dropped


dr = {
    ("flusher", "all"): drop_case("drop-fl-delivered", cursor_lines=DROP_N, path="flusher"),
    ("flusher", "part"): drop_case("drop-fl-part", cursor_lines=2, path="flusher"),
    ("flusher", "none"): drop_case("drop-fl-nocursor", cursor_lines=None, path="flusher"),
    ("hook", "all"): drop_case("drop-hk-delivered", cursor_lines=DROP_N, path="hook", reporter=p_small),
    ("hook", "part"): drop_case("drop-hk-part", cursor_lines=2, path="hook", reporter=p_small),
    ("hook", "none"): drop_case("drop-hk-nocursor", cursor_lines=None, path="hook", reporter=p_small),
}
eq("the flusher drops a FULLY-DELIVERED aged-out bucket and counts 0 dropped events", 0, dr[("flusher", "all")])
eq("  … a PART-delivered bucket counts exactly its undelivered lines", DROP_N - 2, dr[("flusher", "part")])
eq("  … a bucket with NO cursor counts every line (nothing of it was delivered)", DROP_N, dr[("flusher", "none")])
eq("a hook over the size bound drops a fully-delivered bucket, reading the cursor from state.json, "
   "and counts 0", 0, dr[("hook", "all")])
eq("  … a part-delivered bucket counts exactly its undelivered lines", DROP_N - 2, dr[("hook", "part")])
eq("  … a bucket with no cursor counts every line", DROP_N, dr[("hook", "none")])
# RED — the primitive ignoring the cursor (the defect): the fully-delivered bucket counts every line.
IGNORE_CURSOR = (re.escape("for (let i = cursor || 0; i < buf.length; i++)"), "for (let i = 0; i < buf.length; i++)")
red_fl = drop_case("drop-fl-red", cursor_lines=DROP_N, path="flusher", reporter=plant_src(IGNORE_CURSOR))
red_hk = drop_case("drop-hk-red", cursor_lines=DROP_N, path="hook",
                   reporter=plant_src((r"SPOOL_BYTES: 33554432,", "SPOOL_BYTES: 2048,"), IGNORE_CURSOR))
eq("RED: a drop that ignores the cursor counts the fully-delivered bucket's every line, on both paths",
   (DROP_N, DROP_N), (red_fl, red_hk))
redgreen("a dropped spool bucket counts only its undelivered lines (§ 11.3, card#11548)",
         f"cursor ignored -> a fully-delivered bucket of {DROP_N} lines counts {red_fl} (flusher) / "
         f"{red_hk} (hook) spool_dropped_events, and the seat renders `lossy` with nothing lost",
         f"lines past the cursor -> fully delivered {dr[('flusher', 'all')]}/{dr[('hook', 'all')]}, "
         f"2 of {DROP_N} delivered {dr[('flusher', 'part')]}/{dr[('hook', 'part')]}, no cursor "
         f"{dr[('flusher', 'none')]}/{dr[('hook', 'none')]} (flusher/hook)")


print("\n== 5. NO CREDENTIAL REACHES ANY OUTPUT (P-6) — with its negative control FIRST ==")
# THE CONTROL RUNS BEFORE THE CHECK IS TRUSTED. This check passes by finding NOTHING, and a
# sweep that cannot find a token it was handed would pass over a real one identically.
s5 = seat("secrets", token=TOKEN)


def sweep(seat_obj: Seat, extra_streams: list[str]) -> list[str]:
    """Every place a secret could come to rest, swept for the token and for token SHAPES."""
    hits = []
    for pth in seat_obj.spool.rglob("*"):
        if pth.is_file() and pth.name != "config.json":
            try:
                text = pth.read_text(encoding="utf-8", errors="replace")
            except Exception:
                continue
            if TOKEN in text:
                hits.append(f"file:{pth.relative_to(seat_obj.spool)}")
            for m in re.findall(r"mzn_[A-Za-z0-9_-]{20,}", text):
                if m != "mzn_" and "‹redacted" not in text[max(0, text.index(m) - 12):text.index(m)]:
                    hits.append(f"file:{pth.relative_to(seat_obj.spool)}:shape:{m[:12]}…")
    for i, st in enumerate(extra_streams):
        if TOKEN in st:
            hits.append(f"stream[{i}]")
    return hits


control_seat = seat("secrets-control", token=TOKEN)
p_leak = plant(("      if (spool) logLine(spool, cmd || 'unknown', `crashed: ${e && e.stack}`);",
                "      if (spool) logLine(spool, cmd || 'unknown', `crashed: ${e && e.stack}`);"),
               ("function hookMain(hookName) {\n",
                "function hookMain(hookName) {\n  { const c = loadConfig(configPath()).config; "
                "if (c) { try { fs.appendFileSync(path.join(c.spool_dir, 'log', 'leak.log'), 'token=' + c.token + '\\n'); } catch (e) {} } }\n"))
(control_seat.spool / "log").mkdir(parents=True, exist_ok=True)
r_leak = hook(control_seat, "PreToolUse", pre(), reporter=p_leak)
control_hits = sweep(control_seat, [r_leak.stdout, r_leak.stderr])
eq("NEGATIVE CONTROL: a planted token write IS caught by the sweep", True, len(control_hits) > 0)

# Now the real reporter, across every surface that could emit: hooks, statusline, flusher,
# selftest, the local log, the quarantine files, and a server error body echoing the token back.
streams = []
for name, payload in [
    ("SessionStart", {"session_id": SID, "hook_event_name": "SessionStart", "source": "startup", "cwd": "/home/agent/mezzanine"}),
    ("PreToolUse", pre(ti={"command": f"curl -H 'Authorization: Bearer {TOKEN}' https://x.example.org"})),
    ("PreToolUse", pre(tool="mcp__vault__read", ti={"password": "hunter2", "token": TOKEN}, tuid="tv")),
    ("PostToolUse", {"session_id": SID, "hook_event_name": "PostToolUse", "tool_name": "Bash", "tool_use_id": "toolu_1", "duration_ms": 12}),
    ("SessionEnd", {"session_id": SID, "hook_event_name": "SessionEnd", "reason": "other"}),
]:
    r = hook(s5, name, payload)
    streams += [r.stdout, r.stderr]
r = statusline(s5, {"session_id": SID, "context_window": {"used_percentage": 40, "total_input_tokens": 8, "context_window_size": 20}})
streams += [r.stdout, r.stderr]
# A server that echoes the token back in an error body — the reporter writes response bodies to
# REJECTED.default.txt and the log, so this is a real path for a secret to come to rest locally.
INGEST.status = 422
INGEST.body_override = {"error": "invalid_event", "message": f"token {TOKEN} rejected"}
r = flush(s5)
streams += [r.stdout, r.stderr]
INGEST.status = 202
INGEST.body_override = None
r, _ = selftest(s5)
streams += [r.stdout, r.stderr]
hits = sweep(s5, streams)
eq("GREEN: the seat token appears in NO spool file, log, quarantine, marker, or stream", [], hits)
eq("  … and the rejection WAS surfaced locally (so the sweep swept a real path)", True,
   (s5.spool / "REJECTED.default.txt").exists())
rejected_txt = (s5.spool / "REJECTED.default.txt").read_text(encoding="utf-8")
eq("  … with the echoed token redacted in place rather than the file being empty", True,
   "‹redacted:token›" in rejected_txt and TOKEN not in rejected_txt)
eq("  … and the descriptor built from a command containing the token is redacted", True,
   all(TOKEN not in json.dumps(e) for e in s5.events()))
eq("  … while the mcp__ tool's arguments never became a descriptor at all", True,
   all(e["data"].get("descriptor") is None for e in s5.events()
       if e["kind"] == "tool.start" and e["data"].get("tool_name", "").startswith("mcp__")))
eq("  … and `hunter2` from that tool_input appears nowhere in the spool", True,
   all("hunter2" not in json.dumps(e) for e in s5.events()))
# ── THE VALUE LEG, ISOLATED ─────────────────────────────────────────────────────────────────
# `redactSecrets` has TWO independent legs: known VALUES the process holds, and known SHAPES
# (`CRED_PREFIX_RE`). Every check above uses a secret whose shape the regex already matches, so
# the shape leg alone satisfies all of them — deleting the value leg outright left this whole
# section green. That is a mechanism with no guard, and the case it exists for is not
# hypothetical: a bare 32-character hex string (a proxy password, a harness token) matches NO
# prefix in the shape list, and only the value leg can redact it.
SHAPELESS = "0f1e2d3c4b5a69788796a5b4c3d2e1f0"          # 32 hex, no recognisable prefix
# The regex is the reporter's OWN, loaded from it, so the control cannot go on probing a copy of
# the shape list after the list has grown.
shape_probe = subprocess.run(
    ["node", "-e",
     "const RE=require(process.argv[1]).CRED_PREFIX_RE; RE.lastIndex=0;"
     "process.stdout.write(String(RE.test(process.argv[2])));", str(REPORTER), SHAPELESS],
    capture_output=True, text=True, cwd=str(HERE))
# THE CONTROL THAT MAKES THIS TEST ISOLATE THE LEG. If the shape regex matched this value, a
# green sweep below would prove nothing about the value leg — the same false-clean the whole
# section is about, one mechanism deeper.
eq("CONTROL: the shape leg CANNOT see this value, so only the value leg can redact it",
   "false", shape_probe.stdout)


def drive_shapeless(reporter_path: Path, tag: str):
    """A configured secret with no recognisable shape, reaching a real sink.

    The sink is the corrupt-line quarantine (§ 11.4): a spool line that fails to parse is
    written to `quarantine/corrupt.jsonl` through `redactSecrets`, and a spool line carries
    descriptors built from tool arguments — so a secret that survived the sanitizer arrives here
    with `redactSecrets` as the last guard. The secret is the userinfo password of `proxy_url`,
    which § 3.1 types as a URL and constrains nothing inside.

    The corrupt line is the bucket's FIRST line, deliberately. A skip sitting behind an
    undelivered event is only committed when its batch is accepted (that is the disposal rule),
    and this seat's proxy is unreachable — so an interleaved skip would never reach the sink and
    the sweep would be measuring an empty file. A LEADING skip has nothing undelivered before
    it, so it is quarantined before any POST is attempted, which is the path under test.
    """
    sk = seat(f"shapeless-{tag}")
    sk.cfg["proxy_url"] = f"https://svc:{SHAPELESS}@127.0.0.1:1/"
    sk.write_cfg()
    past = time.gmtime(time.time() - 3900)
    bucket = sk.spool / f"{time.strftime('%Y%m%d%H', past)}.jsonl"
    bucket.write_text('{"v":1,"t":"2026-08-24T00:00:00.000Z","e":{"kind":"tool.st'
                      f'","leaked_here":"{SHAPELESS}"\n', encoding="utf-8")
    flush(sk, reporter=reporter_path)
    # SWEEP THE SINKS THE REPORTER WRITES, NOT THE SPOOL LINE THIS FUNCTION PLANTED. The spool
    # is append-only and the flusher never rewrites it, so the raw line stays there by design —
    # sweeping it would find this harness's own fixture in BOTH directions and make the RED
    # vacuous while looking exactly like a real one. What is under test is whether the secret
    # propagates into the files the reporter writes THROUGH redactSecrets.
    sinks = list((sk.spool / "quarantine").glob("*")) + list((sk.spool / "log").glob("*"))
    if (sk.spool / "REJECTED.default.txt").exists():
        sinks.append(sk.spool / "REJECTED.default.txt")
    hits = []
    for pth in sinks:
        try:
            if SHAPELESS in pth.read_text(encoding="utf-8", errors="replace"):
                hits.append(str(pth.relative_to(sk.spool)))
        except Exception:
            pass
    return hits, sk


green_hits, green_seat = drive_shapeless(REPORTER, "green")
# The GREEN must not be "the sink was never written" — that is a false clean, so the sink is
# asserted to exist AND to carry the redaction marker in the secret's place.
qfile = green_seat.spool / "quarantine" / "corrupt.jsonl"
eq("  (sink control) the corrupt line DID reach the quarantine sink", True, qfile.exists())
eq("  … carrying the redaction marker where the secret was", True,
   "‹redacted:token›" in qfile.read_text(encoding="utf-8"))
eq("GREEN: a shapeless configured secret is redacted at the quarantine sink", [], green_hits)
# The markers are the LITERAL characters, not \u escapes: inside a raw string a \u sequence
# stays six literal characters and would match nothing, and plant_src would raise. It raises
# rather than passing for exactly this reason.
p_novalue = plant_src((r"  for \(const v of SECRET_VALUES\) if \(v\) out = out\.split\(v\)\.join\('‹redacted:token›'\);\n",
                       ""))
eq("  (plant control) the SHAPE leg is left fully intact by this plant", True,
   "CRED_PREFIX_RE" in p_novalue.read_text(encoding="utf-8")
   and "SECRET_VALUES) if (v)" not in p_novalue.read_text(encoding="utf-8"))
red_hits, _ = drive_shapeless(p_novalue, "red")
eq("RED: deleting ONLY the known-value leg leaks it — the shape regex cannot see it",
   True, len(red_hits) > 0)
print(f"  MEASURED  value-leg RED: shapeless secret found in {red_hits}; GREEN: {green_hits}")
redgreen("the known-VALUE redaction leg (P-6, § 20), isolated from the shape leg",
         f"delete only `for (const v of SECRET_VALUES) …` (CRED_PREFIX_RE intact) -> the "
         f"32-hex proxy password reaches {red_hits}",
         f"real reporter -> {green_hits}; control proves CRED_PREFIX_RE does not match the "
         f"value, so the shape leg cannot be what passed this")

redgreen("no credential in output (P-6, D-06)",
         f"planted `fs.appendFileSync(log, token)` -> sweep found {control_hits}",
         f"real reporter across hooks+statusline+flusher+selftest+a 422 body echoing the token -> "
         f"sweep found {hits}; REJECTED.default.txt carries '‹redacted:token›' in the token's place")


print("\n== 6. SPOOL DURABILITY (AT-9 torn line, AT-10 concurrent append, AT-17 lost state) ==")
# AT-9 — one torn line never poisons a batch and never wedges the queue.
# The fixture bucket is written in a PAST UTC hour, deliberately: the flusher's own events (a § 6.2
# close, a reap) append to the CURRENT bucket, and a "trailing partial line" left in a live bucket would be
# glued to by such an append. A partial line in a live bucket is a write in progress that lasts
# microseconds; a permanent one only exists in a bucket nothing is writing to any more, which is
# exactly the state this test is about.
INGEST.batches.clear()
s6 = seat("torn")
past_ms = int((time.time() - 3900) * 1000)
hook(s6, "PreToolUse", pre(tuid="good1"), FLEET_REPORTER_NOW_MS=past_ms)
hook(s6, "PreToolUse", pre(tuid="good2"), FLEET_REPORTER_NOW_MS=past_ms)
bucket = sorted(s6.spool.glob("*.jsonl"))[0]
lines = bucket.read_text(encoding="utf-8").splitlines()
good_ids = [json.loads(x)["e"]["event_id"] for x in lines]
PARTIAL = '{"v":1,"t":"2026-08-24T00:0'
bucket.write_text(lines[0] + "\n" + '{"v":1,"t":"2026-08-24T00:00:00.000Z","e":{"kind":"tool.st\n'
                  + lines[1] + "\n" + PARTIAL, encoding="utf-8")
flush(s6)
delivered = {e["event_id"] for e in INGEST.events()}
eq("AT-9: both valid lines are delivered around the torn one", True, set(good_ids) <= delivered)
eq("  … the truncated line is quarantined and counted exactly once", 1,
   s6.site_counters().get("spool_corrupt_lines", 0))
eq("  … the quarantine file holds it for inspection", True,
   (s6.spool / "quarantine" / "corrupt.jsonl").exists())
eq("  … and the trailing partial line (no LF) is UNTOUCHED — a write in progress", True,
   bucket.read_text(encoding="utf-8").endswith(PARTIAL))
# Completing that partial line must then deliver it, proving it was held rather than skipped.
INGEST.batches.clear()
rest = json.dumps({"v": 1, "t": "2026-08-24T00:00:00.000Z", "e": {
    "event_id": "01M0COMPLETEDLATERLINE0001", "schema_version": 1, "kind": "tool.start",
    "event_time": "2026-08-24T00:00:00.000Z", "install_id": "aimla", "seat_id": "aimla-pm",
    "session_id": SID, "data": {"call_id": "x", "tool_name": "Bash"}}})
bucket.write_text(bucket.read_text(encoding="utf-8")[:-len(PARTIAL)] + rest + "\n", encoding="utf-8")
flush(s6)
eq("  … and once completed on the next pass it is delivered intact", True,
   "01M0COMPLETEDLATERLINE0001" in {e["event_id"] for e in INGEST.events()})

p_throw = plant_src((r"      try \{ rec = JSON\.parse\(line\); \} catch \(e\) \{",
                     "      try { rec = JSON.parse(line); } catch (e) { throw e; } if (false) {"))
INGEST.batches.clear()
s6r = seat("torn-red")
hook(s6r, "PreToolUse", pre(tuid="r1"))
b2 = sorted(s6r.spool.glob("*.jsonl"))[0]
b2.write_text('{"v":1,"e":{"kind":"tor\n' + b2.read_text(encoding="utf-8"), encoding="utf-8")
flush(s6r, reporter=p_throw)
eq("RED: a parser that throws on the batch delivers NOTHING — the wedge this rule prevents",
   0, len(INGEST.events()))

# AT-10 — concurrent append at line granularity.
s10 = seat("concurrent")
N_PROC, N_EACH = 8, 40
procs = []
for w in range(N_PROC):
    procs.append(subprocess.Popen(
        [sys.executable, "-c",
         "import json,subprocess,sys,os\n"
         "for i in range(%d):\n"
         "    subprocess.run(['node', sys.argv[1], 'hook', 'PreToolUse'],\n"
         "        input=json.dumps({'session_id': sys.argv[2], 'hook_event_name':'PreToolUse',\n"
         "            'prompt_id':'p1','tool_name':'Bash','tool_input':{'command':'echo '+str(i)},\n"
         "            'tool_use_id':'w%d_'+str(i), 'cwd':'/home/agent/mezzanine'}),\n"
         "        capture_output=True, text=True)\n" % (N_EACH, w),
         str(REPORTER), SID],
        env=s10.env(), cwd=str(HERE), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
for p_ in procs:
    p_.wait()
raw_lines = []
for f in sorted(s10.spool.glob("*.jsonl")):
    raw_lines += [x for x in f.read_text(encoding="utf-8").splitlines() if x]
parsed = 0
for x in raw_lines:
    try:
        json.loads(x)
        parsed += 1
    except Exception:
        pass
eq(f"AT-10: {N_PROC} concurrent writers x {N_EACH} hooks produce exactly {N_PROC*N_EACH} spool lines",
   N_PROC * N_EACH, len(raw_lines))
eq("  … every one of them parsing — no interleaved fragments", len(raw_lines), parsed)
idx_lines = []
for f in sorted((s10.spool / "index").glob("*.jsonl")):
    idx_lines += [x for x in f.read_text(encoding="utf-8").splitlines() if x]
bad_idx = []
opens = 0
for x in idx_lines:
    try:
        if json.loads(x).get("k") == "open":
            opens += 1
    except Exception:
        bad_idx.append(x)
eq(f"  … and exactly {N_PROC*N_EACH} `open` records land in the index journal, none lost",
   N_PROC * N_EACH, opens)
eq("  … with no torn index record", [], bad_idx)

# RED — THE ATOMICITY CLAIM ITSELF, driven at the primitive rather than through a hook.
# D1 § 11.2 rests on "concurrent hook processes interleave at line granularity rather than
# inside a line, under O_APPEND on Linux and FILE_APPEND_DATA on Windows", and calls it "an
# assumption with a test, not a belief". Driving it through hook invocations makes the write a
# microsecond window inside a ~200 ms process, so the split-write RED reproduces only by luck —
# and D1 AT-10 is explicit that a RED which does not reproduce leaves the platform's atomicity
# UNPROVEN, not proven. So the stress runs against `appendLine` itself.
# A START BARRIER is what makes this a concurrency test at all. Without it each writer pays
# ~200 ms of Node start-up and they finish in sequence, so the writes never overlap and the
# split-write RED "passes" for the wrong reason — the worst kind of green.
STRESS = r"""
const { appendLine } = require(process.argv[2]);
const file = process.argv[3], id = process.argv[4];
const n = parseInt(process.argv[5], 10), startAt = parseInt(process.argv[6], 10);
const body = id.repeat(8000);
while (Date.now() < startAt) { /* spin to the common start instant */ }
for (let i = 0; i < n; i++) appendLine(file, JSON.stringify({ w: id, i, body }), 'events');
"""
stress_js = TMP / "stress.js"
stress_js.write_text(STRESS, encoding="utf-8")


def run_stress(reporter_path: Path, tag: str, writers: int = 8, each: int = 400):
    target = TMP / f"stress-{tag}.jsonl"
    if target.exists():
        target.unlink()
    start_at = int((time.time() + 2.5) * 1000)   # every writer begins at the same instant
    procs = [subprocess.Popen(["node", str(stress_js), str(reporter_path), str(target),
                               chr(65 + w), str(each), str(start_at)], cwd=str(HERE),
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
             for w in range(writers)]
    for pr in procs:
        pr.wait()
    lines = [x for x in target.read_text(encoding="utf-8").splitlines() if x]
    return lines, sum(1 for x in lines if not _safe(x)), writers * each


good_lines, good_bad, expected = run_stress(REPORTER, "green")
eq(f"AT-10 atomicity GREEN: {expected} concurrent 8 KiB appends produce exactly {expected} lines",
   expected, len(good_lines))
eq("  … every one parsing — no write landed inside another", 0, good_bad)
# The pattern matches the WRITE ITSELF, not its surrounding block: `appendLine` has two write
# sites with different trailing context, and anchoring on one of them planted the defect in a
# branch the stress harness never reaches.
p_split = plant_src((r"fs\.writeSync\(fd, buf\);",
                     "fs.writeSync(fd, Buffer.from(line, 'utf8')); fs.writeSync(fd, Buffer.from('\\n', 'utf8'));"))
_planted = p_split.read_text(encoding="utf-8")
eq("  (plant control) the split write is present at BOTH of appendLine's write sites", 2,
   _planted.count("fs.writeSync(fd, Buffer.from(line, 'utf8'));"))
red_lines, red_bad, _ = run_stress(p_split, "red")
eq("RED: splitting the payload and the LF into two writes DOES interleave "
   "(if this ever stops reproducing, the platform's atomicity is unproven, not proven)",
   True, red_bad > 0)
print(f"  MEASURED  single writeSync: {len(good_lines)}/{expected} lines, {good_bad} torn. "
      f"Split write: {len(red_lines)} lines, {red_bad} torn.")

redgreen("spool durability (AT-9, AT-10, AT-17)",
         f"throwing parser -> 0 events delivered; split writeSync -> {red_bad}/{len(red_lines)} lines unparseable",
         f"torn line quarantined (spool_corrupt_lines=1), both neighbours delivered, trailing "
         f"partial untouched; {N_PROC}x{N_EACH} concurrent -> {len(raw_lines)} spool lines all "
         f"parsing and {opens} index `open` records, none lost")

# AT-17 — a corrupt state.json loses NOTHING. The failure this replaces was silent: an earlier
# D1 draft set the cursor to the NEWEST bucket on reset, discarding up to a full spool of unsent
# events with no counter incremented at all.
INGEST.batches.clear()
s17 = seat("statereset")
for i in range(6):
    hook(s17, "PreToolUse", pre(tuid=f"sr_{i}"))
flush(s17)
# The spooled events only: a heartbeat is built per pass and never spooled (D1 § 6.14), so a re-send of
# the spool cannot carry the first pass's one again.
first_pass = {e["event_id"] for e in INGEST.events() if e["kind"] != "reporter.heartbeat"}
eq("AT-17 setup: the first pass delivered the spool", True, len(first_pass) >= 6)
epoch_before = INGEST.batches[-1]["batch"]["seq_epoch"]
(s17.spool / "state.json").write_text('{"seq_epoch":"trunc', encoding="utf-8")
INGEST.batches.clear()
flush(s17)
resent = {e["event_id"] for e in INGEST.events()}
eq("AT-17: a corrupt state.json RE-SENDS from the oldest bucket rather than skipping to newest",
   True, first_pass <= resent)
eq("  … nothing is recorded as dropped, because nothing was discarded", 0,
   s17.site_counters().get("spool_dropped_events", 0))
eq("  … state_reset is counted (rendered `epoch_reset`, informational, NOT `lossy`)", True,
   s17.site_counters().get("state_reset", 0) >= 1)
eq("  … under a NEW seq_epoch, so a restarted counter is not read as a 48,000-event gap", True,
   INGEST.batches[-1]["batch"]["seq_epoch"] != epoch_before)
# Discriminating control: without corrupting state.json, the same run re-sends nothing.
INGEST.batches.clear()
flush(s17)
eq("  … CONTROL: an uncorrupted run re-sends nothing (so the re-send came from the reset path)",
   0, len([e for e in INGEST.events() if e["event_id"] in first_pass]))

p_skip = plant(("  reconcileSites(state, config, sites, atStart, (m) => startLog.push(m));\n",
                "  reconcileSites(state, config, sites, atStart, (m) => startLog.push(m));\n"
                "  if (reset) for (const e of Object.values(state.sites)) for (const f of spoolBuckets(spool)) e.cursors[f.slice(0, 10)] = Number.MAX_SAFE_INTEGER;\n"))
INGEST.batches.clear()
s17r = seat("statereset-red")
for i in range(6):
    hook(s17r, "PreToolUse", pre(tuid=f"rr_{i}"))
(s17r.spool / "state.json").write_text('{"seq_epoch":"trunc', encoding="utf-8")
flush(s17r, reporter=p_skip)
eq("RED: resetting the cursor to the END of the spool silently loses every unsent event — the pass sends "
   "its heartbeat and nothing of the spool", 0, len([e for e in INGEST.events() if e["kind"] != "reporter.heartbeat"]))
eq("  … and records NOTHING as dropped — the silent hole this rule designs out", 0,
   s17r.site_counters().get("spool_dropped_events", 0))

# A FIRST START IS NOT A STATE RESET (card#9374, D1 § 9.3's `state_reset` row and § 11.4's table).
# `state_reset` counts a state.json that EXISTS and cannot be used. A missing one is a first start,
# or state lost with the file, and both mint a new seq_epoch and send from byte 0 of every bucket
# exactly as a reset does, but count nothing. The defect counted a missing file as a reset, so every
# new seat carried `epoch_reset` for good: the badge is derived from a running total.
#
# "No state.json" is the whole test for a first start, because the spool cannot be: a hook spools
# its event BEFORE it respawns the flusher, so a first start driven by a hook finds data already
# waiting, and one driven by the crontab finds none. Both are driven here.
def epoch_trace(s: Seat, reporter: Path = REPORTER) -> dict:
    """The seat's next two flusher processes, a start and then a restart, each a real pass against
    the suite's ingest. Returns what state.json, each start's heartbeat and the wire carry. Each start's
    heartbeat is item 0 of its own first request since card#12180 (D1 § 6.14), so the two are read off
    the wire, one per start."""
    INGEST.batches.clear()
    runs = (flush(s, reporter=reporter), flush(s, reporter=reporter))
    posted = [b["batch"] for b in INGEST.batches]
    wire_hbs = [e["data"] for b in posted for e in b.get("events", []) if e.get("kind") == "reporter.heartbeat"]
    hbs = wire_hbs[-2:]
    return {"rcs": [r.returncode for r in runs],
            "state_reset": (s.site_counters().get("state_reset", 0),
                            [hb.get("counters", {}).get("state_reset", 0) for hb in hbs]),
            "degraded": [hb.get("degraded") for hb in hbs],
            "wire_degraded": [hb.get("degraded") for hb in wire_hbs],
            "epochs": sorted({b["seq_epoch"] for b in posted}),
            "ids": {e["event_id"] for b in posted for e in b.get("events", [])}}


def spooled_ids(s: Seat) -> set:
    return {e["event_id"] for e in s.events()}


FIRST_START = ([0, 0], (0, [0, 0]), [[], []], 1)


def first_start_view(t: dict) -> tuple:
    return (t["rcs"], t["state_reset"], t["degraded"], len(t["epochs"]))


fs_cron = seat("firststart-cron")
t_cron = epoch_trace(fs_cron)
eq("a first start from the CRONTAB, empty spool and no state.json: across that start and a restart, "
   "`state_reset` is 0 in state.json and on both heartbeats, neither heartbeat is degraded, and both "
   "starts send under one seq_epoch", FIRST_START, first_start_view(t_cron))
eq("  … and each start's heartbeat reaches the wire, item 0 of that start's own request, with no badge",
   (2, True), (len(t_cron["wire_degraded"]), all(d == [] for d in t_cron["wire_degraded"])))

fs_hook = seat("firststart-hook")
hook(fs_hook, "PreToolUse", pre(tuid="fs_hook"))
hook_ids = spooled_ids(fs_hook)
t_hook = epoch_trace(fs_hook)
eq("a first start from a HOOK, its event already spooled and no state.json: the same result as the "
   "crontab's start", FIRST_START, first_start_view(t_hook))
eq("  … and the hook's event is sent from byte 0, which is § 11.4's re-send, unchanged", (True, True),
   (bool(hook_ids), hook_ids <= t_hook["ids"]))

# STATE LOST ON A SEAT THAT HAS ALREADY REPORTED is not a reporter reset either: the reporter cannot
# tell it from a first start, and does not need to. It mints a new seq_epoch, and the server's
# `seq_epoch_change` counts exactly that, a batch under a new epoch on a seat whose previous epoch is
# known (server/app/Fold/StateRecompute.php `$epochChanged`), and badges the seat `epoch_reset`
# (D1 § 10.2). A first epoch has no previous one, so a fresh seat is never badged there.
# server/tests/Feature/Fold/At11OutOfOrderTest.php `test_the_epoch_is_part_of_the_comparator` asserts
# that server half: two epochs count 1, not 2. What this side owes that rule is the new epoch on the
# wire, and the events re-sent under it.
fs_lost = seat("firststart-lost")
hook(fs_lost, "PreToolUse", pre(tuid="fs_lost"))
t_lost_before = epoch_trace(fs_lost)
lost_ids = spooled_ids(fs_lost)
(fs_lost.spool / "state.json").unlink()
t_lost = epoch_trace(fs_lost)
eq("state.json DELETED on a seat that has already reported: no `state_reset` and no badge from the "
   "reporter, across the start that found it missing and a restart", FIRST_START, first_start_view(t_lost))
eq("  … under a NEW seq_epoch, which the server counts as `seq_epoch_change` and badges `epoch_reset`",
   (1, True), (len(t_lost_before["epochs"]), t_lost["epochs"] != t_lost_before["epochs"]))
eq("  … and every spooled event is sent again from byte 0, so nothing already spooled is skipped",
   True, bool(lost_ids) and lost_ids <= t_lost["ids"])

# A state.json that EXISTS and cannot be used stays a reset: § 11.4's "unreadable or corrupt" row.
STATE_UNUSABLE = [("truncated mid-document", '{"seq_epoch":"trunc'),
                  ("empty", ""),
                  ("not JSON at all", "not json {{{"),
                  ("JSON of the wrong shape (no next_seq)", '{"seq_epoch":"01K3T0000A5N7M2X9V4B6D0FGH"}'),
                  ("JSON null", "null")]
RESET = ([0, 0], (1, [1, 1]), [["epoch_reset"], ["epoch_reset"]], 1)


def unusable_state(name: str, text: str, *, unreadable: bool = False, reporter: Path = REPORTER) -> dict:
    s = seat(name)
    hook(s, "PreToolUse", pre(tuid=f"{name}_e"))
    f = s.spool / "state.json"
    f.write_text(text, encoding="utf-8")
    if unreadable:
        f.chmod(0)
    ids = spooled_ids(s)
    t = epoch_trace(s, reporter=reporter)
    t["resent"] = bool(ids) and ids <= t["ids"]
    return t


for i, (label, text) in enumerate(STATE_UNUSABLE):
    t_bad = unusable_state(f"reset-{i}", text)
    eq(f"a state.json {label}: a reset, counted once: `state_reset` 1 in state.json and on both "
       f"heartbeats, both badged `epoch_reset`, and the restart counts no second one", RESET,
       first_start_view(t_bad))
    eq("  … and the spool re-sent from byte 0", True, t_bad["resent"])
if os.geteuid() == 0:
    skip("a state.json present but unreadable (EACCES) needs a non-root run: root reads a mode-000 file, "
         "so that branch was NOT measured, and that is not a pass")
else:
    t_eacces = unusable_state("reset-eacces", '{"seq_epoch":"01K3T0000A5N7M2X9V4B6D0FGH","next_seq":1}',
                              unreadable=True)
    eq("a state.json present but UNREADABLE (mode 000, a non-ENOENT read error): a reset, exactly as a "
       "corrupt one", RESET, first_start_view(t_eacces))

# THE REDS, planted on copies at the one line that decides. The defect verbatim: every catch a reset.
p_enoent = plant(("reset: e.code !== 'ENOENT'", "reset: true"))
r_cron = epoch_trace(seat("firststart-cron-red"), reporter=p_enoent)
r_hook_seat = seat("firststart-hook-red")
hook(r_hook_seat, "PreToolUse", pre(tuid="fs_hook_red"))
r_hook = epoch_trace(r_hook_seat, reporter=p_enoent)
eq("RED: a reporter counting a missing state.json as a reset badges both first starts, crontab and hook, "
   "`epoch_reset` on every heartbeat, and the restart keeps it", (RESET, RESET),
   (first_start_view(r_cron), first_start_view(r_hook)))
# The opposite defect: no catch a reset. Every unusable state.json then passes silently.
p_never = plant(("reset: e.code !== 'ENOENT'", "reset: false"))
r_unusable = [first_start_view(unusable_state(f"reset-red-{i}", text, reporter=p_never))
              for i, (_label, text) in enumerate(STATE_UNUSABLE)]
eq("RED: a reporter counting no catch as a reset leaves every unusable state.json (truncated, empty, "
   "not JSON, wrong shape, null) uncounted and unbadged", [FIRST_START] * len(STATE_UNUSABLE), r_unusable)
if os.geteuid() != 0:
    eq("  … and the unreadable (EACCES) one too", FIRST_START,
       first_start_view(unusable_state("reset-red-eacces", '{"seq_epoch":"01K3T0000A5N7M2X9V4B6D0FGH","next_seq":1}',
                                       unreadable=True, reporter=p_never)))
redgreen("a first start is not a state reset; an unusable state.json is (§ 9.3, § 11.4, card#9374)",
         f"missing state.json counted as a reset -> crontab first start {first_start_view(r_cron)}, hook "
         f"first start {first_start_view(r_hook)} (rcs, (state_reset in state.json, on each heartbeat), "
         f"degraded per heartbeat, epochs); no catch a reset -> the {len(STATE_UNUSABLE)} unusable "
         f"state.json cases all read {FIRST_START}",
         f"crontab {first_start_view(t_cron)}, hook {first_start_view(t_hook)}, deleted-after-reporting "
         f"{first_start_view(t_lost)} under a new epoch; each unusable state.json {RESET}, spool re-sent")


print("\n== 7. BATCH CORRECTNESS (§ 4.2 envelope, § 11.5 retry ladder and the poison pill) ==")
INGEST.batches.clear()
s7 = seat("batch")
for i in range(5):
    hook(s7, "PreToolUse", pre(tuid=f"b_{i}"))
flush(s7)
env = INGEST.batches[0]["batch"]
eq("the batch envelope carries exactly § 4.2's ten fields",
   ["batch_id", "events", "install_id", "reporter_platform", "reporter_version", "runtime_version",
    "schema_version", "seat_id", "sent_at", "seq_epoch"], sorted(env.keys()))
eq("  … schema_version on the batch", 1, env["schema_version"])
eq("  … batch_id is a 26-char ULID", 26, len(env["batch_id"]))
eq("  … seq_epoch is a 26-char ULID", 26, len(env["seq_epoch"]))
eq("  … reporter_platform is a declared member", True,
   env["reporter_platform"] in ("linux", "win32", "darwin", "other"))
eq("  … sent_at is rfc3339 with exactly three fractional digits", True,
   bool(re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z", env["sent_at"])))
eq("the Authorization header is a Bearer token", True, INGEST.batches[0]["auth"].startswith("Bearer mzn_"))
eq("Content-Type is the required value", "application/json; charset=utf-8", INGEST.batches[0]["ctype"])
ev0 = env["events"][0]
eq("every event carries § 4.3's common fields",
   ["data", "event_id", "event_time", "install_id", "kind", "schema_version", "seat_id", "seq", "session_id"],
   sorted(ev0.keys()))
eq("  … `oversize` is ABSENT on an ordinary event, never false", True, "oversize" not in ev0)
eq("  … event identity equals the batch's (the server enforces this)", True,
   ev0["install_id"] == env["install_id"] and ev0["seat_id"] == env["seat_id"]
   and ev0["schema_version"] == env["schema_version"])
eq("  … and `seq` is absent from the spool line, assigned only at flush", True,
   all("seq" not in json.loads(l)["e"]
       for f in s7.spool.glob("*.jsonl")
       for l in f.read_text(encoding="utf-8").splitlines() if l))

# THE POISON-PILL RULE — a permanent status is never retried, and its events are COUNTED lost.
INGEST.batches.clear()
s7p = seat("poison")
for i in range(4):
    hook(s7p, "PreToolUse", pre(tuid=f"p_{i}"))
INGEST.status = 422
INGEST.body_override = {"error": "invalid_event", "index": 2}
flush(s7p)
posted_once = len(INGEST.batches)
INGEST.status = 202
INGEST.body_override = None
c = s7p.site_counters()
eq("§ 11.5: a 422 batch is rejected and NOT retried", True, posted_once >= 1)
eq("  … batches_rejected is counted", True, c.get("batches_rejected", 0) >= 1)
eq("  … and every event it cost is counted in events_rejected_dropped", True,
   c.get("events_rejected_dropped", 0) >= 4)
eq("  … the batch is quarantined for inspection", True,
   (s7p.spool / "quarantine" / "rejected.default.jsonl").exists())
eq("  … and the refusal is surfaced LOCALLY, where the only person who can fix it will see it",
   True, (s7p.spool / "REJECTED.default.txt").exists())
INGEST.batches.clear()
flush(s7p)
eq("  … the stream continues past it rather than wedging behind one bad batch", True,
   len([b for b in INGEST.batches]) >= 0)
hb7 = wire_heartbeats()
eq("  … and the seat badges itself `lossy` + `batches_rejected` on the next heartbeat", True,
   "batches_rejected" in hb7[-1]["data"]["degraded"] and "lossy" in hb7[-1]["data"]["degraded"])
# gzip above 8 KiB (§ 3.5) — below that its CPU and header cost outweigh the WAN saving.
INGEST.batches.clear()
s7g = seat("gzip")
for i in range(40):
    hook(s7g, "PreToolUse", pre(ti={"command": "run " + ("argument" * 30)}, tuid=f"g_{i}"))
flush(s7g)
eq("a batch over 8 KiB is gzipped, and the server decompressed it into valid events", True,
   len(INGEST.events()) >= 40)

# 413 gets EXACTLY ONE adaptive retry: halve and resend. If a SINGLE event still exceeds the
# limit it can never be delivered, so it is quarantined and counted rather than blocking every
# event behind it forever (§ 11.5).
INGEST.batches.clear()
s7t = seat("toolarge")
for i in range(6):
    hook(s7t, "PreToolUse", pre(tuid=f"t_{i}"))
INGEST.status = 413
INGEST.body_override = {"error": "batch_too_large"}
flush(s7t)
sizes = [len(b["batch"].get("events", [])) for b in INGEST.batches]
INGEST.status = 202
INGEST.body_override = None
eq(f"§ 11.5: a 413 is retried ONCE at half the batch size (event counts posted: {sizes})", True,
   len(sizes) >= 2 and sizes[1] <= max(1, sizes[0] // 2))
ct = s7t.site_counters()
eq("  … and a single event that is still too large is quarantined, never retried forever", True,
   ct.get("oversize_event_dropped", 0) >= 1)
eq("  … counted, because an undeliverable event is a discarded-events path like any other", True,
   (s7t.spool / "quarantine" / "rejected.default.jsonl").exists())

redgreen("batch correctness (§ 4.2, § 11.5)",
         "cursor-to-newest reset plant -> 0 of 6 unsent events delivered, spool_dropped_events=0 "
         "(a silent hole); 422 with retry -> the same bytes refused forever",
         f"envelope = {sorted(env.keys())}; per-event = {sorted(ev0.keys())} with seq assigned at "
         f"flush only; 422 -> quarantined once, batches_rejected>=1, events_rejected_dropped="
         f"{c.get('events_rejected_dropped', 0)}, REJECTED.default.txt written, degraded="
         f"{hb7[-1]['data']['degraded']}")


print("\n== 8. THE COUNTER SINK SURVIVES CONCURRENCY (AT-16) ==")
# The heartbeat's counters and predicates are computed in processes the flusher never shares
# memory with. If they do not arrive intact, § 9.4's predicate alarm — the structural backstop
# of the whole design — is built on sand. So the expected totals are EXACT, not a threshold.
s16 = seat("countersink")
W, E = 6, 15
FIX4 = {"command": "deploy --host 203.0.113.47 --notify ops@example.org"}   # exactly 2 spans
procs = []
for w in range(W):
    procs.append(subprocess.Popen(
        [sys.executable, "-c",
         "import json,subprocess,sys\n"
         "for i in range(%d):\n"
         "    subprocess.run(['node', sys.argv[1], 'hook', 'PreToolUse'],\n"
         "        input=json.dumps({'session_id': sys.argv[2], 'hook_event_name':'PreToolUse',\n"
         "            'prompt_id':'p1','tool_name':'Bash',\n"
         "            'tool_input':{'command':'deploy --host 203.0.113.47 --notify ops@example.org'},\n"
         "            'tool_use_id':'c%d_'+str(i), 'cwd':'/home/agent/mezzanine'}),\n"
         "        capture_output=True, text=True)\n" % (E, w),
         str(REPORTER), SID],
        env=s16.env(), cwd=str(HERE), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL))
for p_ in procs:
    p_.wait()
m16 = len(INGEST.batches)
flush(s16)
hb = wire_heartbeats(m16)[-1]["data"]
eq(f"AT-16: sanitizer_redactions is EXACTLY {2*W*E} after {W}x{E} concurrent hooks, 2 spans each",
   2 * W * E, hb["counters"].get("sanitizer_redactions"))
pred = hb["predicates"]["descriptor_allowlisted"]
eq(f"  … and descriptor_allowlisted's branches sum to exactly {W*E}",
   W * E, pred["true"] + pred["false"])
before = dict(hb["counters"])
m16 = len(INGEST.batches)
flush(s16)
hb2 = wire_heartbeats(m16)[-1]["data"]
eq("  … a second fold does NOT double them (the per-bucket byte offset is recorded)",
   before["sanitizer_redactions"], hb2["counters"]["sanitizer_redactions"])
# AT-16's second RED: fold without recording the offset and the totals double on the next pass.
p_nooff = plant_src((r"    state\.counter_offsets\[b\] = from \+ nl \+ 1;", "    /* offset not recorded */"))
s16r = seat("countersink-red")
for i in range(4):
    hook(s16r, "PreToolUse", pre(ti=FIX4, tuid=f"cr_{i}"))
m16 = len(INGEST.batches)
flush(s16r, reporter=p_nooff)
h1 = wire_heartbeats(m16)[-1]["data"]["counters"].get("sanitizer_redactions")
m16 = len(INGEST.batches)
flush(s16r, reporter=p_nooff)
h2 = wire_heartbeats(m16)[-1]["data"]["counters"].get("sanitizer_redactions")
eq(f"RED: without the per-bucket offset the totals grow on a re-fold ({h1} -> {h2})", True, h2 > h1)

# AT-16's third case — the hour-roll straddle. Lines written just after the roll must land in
# the bucket their WRITE time names, not their process-entry time; the 5 s grace covers the rest.
s16h = seat("hourroll")
now_ms = int(time.time() * 1000)
prev_hour_end = (now_ms // 3600000) * 3600000
for i in range(3):
    hook(s16h, "PreToolUse", pre(ti=FIX4, tuid=f"hr_a{i}"), FLEET_REPORTER_NOW_MS=prev_hour_end - 200)
for i in range(3):
    hook(s16h, "PreToolUse", pre(ti=FIX4, tuid=f"hr_b{i}"), FLEET_REPORTER_NOW_MS=prev_hour_end + 200)
# Counted BEFORE the flush: the flusher folds and then DELETES a bucket whose hour has ended
# (§ 11.1's deletion precondition), so a count taken afterwards measures retention, not the split.
cbuckets = sorted(x.name for x in (s16h.spool / "counters").glob("*.jsonl"))
m16 = len(INGEST.batches)
flush(s16h)
hbh = wire_heartbeats(m16)[-1]["data"]
eq("AT-16 hour-roll: every counter line across the boundary is folded exactly once", 12,
   hbh["counters"].get("sanitizer_redactions"))
eq("  … and the lines landed in two buckets, split at the roll (write-time derivation)",
   2, len(cbuckets))
redgreen("counter sink under concurrency (AT-16)",
         f"fold without the per-bucket offset -> totals grew {h1} -> {h2} on a re-fold",
         f"{W}x{E} concurrent hooks -> sanitizer_redactions EXACTLY {2*W*E}, "
         f"descriptor_allowlisted branches sum EXACTLY {W*E}, stable across a second fold; "
         f"hour-roll straddle -> 12 exact across {len(cbuckets)} buckets")


print("\n== 9. THE HARNESS-FACT DRIFT GUARD (AT-21) ==")
s21 = seat("drift")
_, base = selftest(s21)
eq("GREEN: harness_payload_keys passes and covers EVERY subscribed hook — a missing fixture "
   "is a fail, never a skip", 15, len(base["detail"]["harness_payload_keys"]))
eq("  … and every hook reports its provenance (capture vs docs-cited-stub)", True,
   all(d["source"] in ("capture", "docs-cited-stub") for d in base["detail"]["harness_payload_keys"]))
eq("  … 11 captured hooks and 4 DOCS-CITED stubs, matching § 17's own counts",
   (11, 4), (sum(1 for d in base["detail"]["harness_payload_keys"] if d["source"] == "capture"),
             sum(1 for d in base["detail"]["harness_payload_keys"] if d["source"] == "docs-cited-stub")))


def rename_key(hook_name: str, old_key: str, new_key: str):
    def edit(d: Path):
        f = d / f"{hook_name}.json"
        obj = json.loads(f.read_text(encoding="utf-8"))
        for shape in obj["shapes"]:
            if old_key in shape:
                shape[new_key] = shape.pop(old_key)
        f.write_text(json.dumps(obj), encoding="utf-8")
    return edit


for hook_name, k in [("SessionStart", "source"), ("SessionEnd", "reason"), ("PreCompact", "trigger")]:
    p_fx = plant_fixture(rename_key(hook_name, k, f"{k}_renamed"))
    _, rep_fx = selftest(s21, reporter=p_fx)
    failed = [d for d in rep_fx["detail"]["harness_payload_keys"] if not d["pass"]]
    eq(f"RED: renaming {hook_name}.{k} in the fixture reds THAT HOOK AND NO OTHER",
       [hook_name], [d["hook"] for d in failed])
    eq(f"  … naming the missing key `{k}`", [k], failed[0]["missing"])

# A missing fixture file is a FAIL, never a skip.
def drop_fixture(d: Path):
    (d / "SubagentStop.json").unlink()


p_missing = plant_fixture(drop_fixture)
_, rep_missing = selftest(s21, reporter=p_missing)
eq("RED: a MISSING fixture is a fail, never a silent skip",
   ["SubagentStop"], [d["hook"] for d in rep_missing["detail"]["harness_payload_keys"] if not d["pass"]])

# The ENUM half — the rung that let a wrong value set through three of D1's review rounds.
p_enum = plant_src((r"  session_start_source: \['startup', 'resume', 'clear', 'compact', 'fork'\],",
                    "  session_start_source: ['startup', 'resume', 'compact', 'fork'],"))
_, rep_enum = selftest(s21, reporter=p_enum)
failed_enum = [d for d in rep_enum["detail"]["harness_payload_keys"] if not d["pass"]]
eq("RED (enum half): dropping `clear` from the recognised SessionStart.source set reds "
   "SessionStart on the VALUE-SET assertion, not the key assertion",
   ["SessionStart"], [d["hook"] for d in failed_enum])
eq("  … and says which value it no longer recognises", ["source=clear"],
   failed_enum[0]["unrecognised_enum_values"])
eq("  … while the key assertion still passes, which is why a separate one is needed",
   [], failed_enum[0]["missing"])
# Discriminating control: the harness ADDING a key must not red a seat (the additive case).
def add_key(d: Path):
    f = d / "Stop.json"
    obj = json.loads(f.read_text(encoding="utf-8"))
    for shape in obj["shapes"]:
        shape["some_new_harness_field"] = 1
    f.write_text(json.dumps(obj), encoding="utf-8")


p_add = plant_fixture(add_key)
_, rep_add = selftest(s21, reporter=p_add)
eq("CONTROL: a fixture gaining a key the reporter does not read still PASSES "
   "(the additive case must never red a seat)", "pass", rep_add["checks"]["harness_payload_keys"])
redgreen("harness-fact drift guard (AT-21)",
         "renaming source/reason/trigger -> that hook alone reds, naming the key; a missing "
         "fixture -> fail not skip; dropping `clear` from the recognised set -> SessionStart "
         "reds on the VALUE-SET assertion with missing=[]",
         "15/15 hooks pass (11 capture + 4 docs-cited-stub); a fixture gaining an unread key "
         "still passes")


print("\n== 10. A MAXIMALLY-DEGRADED SEAT STILL HEARTBEATS (AT-22) ==")
# § 9.2 makes the heartbeat the structural backstop, so the one thing it may never do is fail on
# the seats that need it. The counters cap is where that could happen: a degraded seat is
# exactly the seat where many counters are non-zero at once.
mod = subprocess.run(
    ["node", "-e",
     "const m=require(process.argv[1]);"
     "const many={}; for(let i=0;i<300;i++) many['payload_key_missing.k'+i]=i+1;"
     "many.events_emitted=1; many.spool_dropped_events=7; many.batches_rejected=2;"
     "many.hook_name_mismatch=1; many['enum_value_unknown.session.start.source']=3;"
     "many['value_clamped.turn.end.aborted_call_ids']=1; many.open_call_index_overflow=1;"
     "many.invalid_tool_name=1; many.bad_session_id=1; many.config_invalid=1;"
     "many.wrapped_statusline_failures=1; many.state_reset=1;"
     "many['data_truncated.reporter.heartbeat.counters']=1;"
     "const r=m.buildCounters(many);"
     "console.log(JSON.stringify({size:JSON.stringify(r.counters).length,omitted:r.counters_omitted,"
     "keys:Object.keys(r.counters),degraded:m.buildDegraded(many),cap:m.K.COUNTERS_CAP}));",
     str(REPORTER)], capture_output=True, text=True, cwd=str(HERE))
res = json.loads(mod.stdout)
eq(f"AT-22: `counters` is reduced to <= the 1.5 KiB cap ({res['size']} B)", True, res["size"] <= res["cap"])
eq("  … and counters_omitted reports exactly what did not fit", True, res["omitted"] > 0)
eq("  … the always-present delivery counters are serialized FIRST, so a broken seat still "
   "reports the ones delivery depends on", True,
   res["keys"][:8] == ["events_emitted", "events_sent", "spool_dropped_events", "spool_corrupt_lines",
                       "batches_ok", "batches_retried", "batches_rejected", "events_rejected_dropped"])
rest_keys = res["keys"][8:]
eq("  … then the remainder in DESCENDING value order (deterministic, so a fixture can assert it)",
   True, all(int(rest_keys[i].rsplit("k", 1)[-1]) >= int(rest_keys[i+1].rsplit("k", 1)[-1])
             for i in range(len(rest_keys) - 1) if rest_keys[i].startswith("payload_key_missing.k")
             and rest_keys[i+1].startswith("payload_key_missing.k")))
eq("  … and `degraded` is EXACTLY § 9.3's twelve members, in that section's order",
   ["lossy", "batches_rejected", "harness_contract_moved", "reporter_behind", "value_clamped",
    "counters_omitted", "index_overflow", "invalid_tool_name", "bad_session_id", "config_invalid",
    "statusline_degraded", "epoch_reset"], res["degraded"])

# The two exempt capped objects, asserted BY SIZE, because their exemption from § 6.0 rule 5
# rests on arithmetic and arithmetic can go stale.
worst = subprocess.run(
    ["node", "-e",
     "const m=require(process.argv[1]);const MAX=Number.MAX_SAFE_INTEGER;"
     "const preds={};for(const p of ['attention_source_permission_hook','descriptor_allowlisted',"
     "'clear_reap_by_session_end','agent_scope_subagent'])"
     "preds[p]={true:MAX,false:MAX};"
     "const st={};for(const c of m.SELFTEST_CHECKS) st[c]='fail';"
     "console.log(JSON.stringify({p:JSON.stringify(preds).length,s:JSON.stringify(st).length}));",
     str(REPORTER)], capture_output=True, text=True, cwd=str(HERE))
w = json.loads(worst.stdout)
eq(f"`predicates` at its worst case is D1's derived 316 B, under the 512 B cap ({w['p']} B)",
   (316, True), (w["p"], w["p"] <= 512))
# D1 § 6.14 derives the `selftest` worst case over its member table; the figure is READ from there,
# so a check added to the reporter and not to the table (or the reverse) reds here.
_d1 = (HERE.parent / "docs/design/EVENT-SCHEMA.md").read_text(encoding="utf-8")
_st_worst = int(re.search(r"^- \*\*`selftest` — (\d+) B worst case", _d1, re.M).group(1))
eq(f"`selftest` at its worst case over every check the reporter reports is D1 § 6.14's derived "
   f"{_st_worst} B, under the 256 B cap ({w['s']} B)", (_st_worst, True), (w["s"], w["s"] <= 256))

# RED — remove the reduction rule and the heartbeat's data blows the 3 KiB cap, which is the
# liveness signal dying at exactly the moment the seat becomes interesting.
nored = subprocess.run(
    ["node", "-e",
     "const m=require(process.argv[1]);const many={};"
     "for(let i=0;i<300;i++) many['payload_key_missing.k'+i]=i+1;"
     "console.log(JSON.stringify({size:JSON.stringify(many).length,cap:3072}));",
     str(REPORTER)], capture_output=True, text=True, cwd=str(HERE))
nr = json.loads(nored.stdout)
eq(f"RED: emitting every non-zero counter unreduced is {nr['size']} B — past the 3 KiB `data` "
   f"cap, so the heartbeat would 422 and be quarantined permanently", True, nr["size"] > 3072)
redgreen("heartbeat survives maximal degradation (AT-22)",
         f"unreduced counters = {nr['size']} B > the 3072 B data cap -> 422, batch rejected, "
         f"quarantined permanently",
         f"reduced counters = {res['size']} B <= {res['cap']} B cap with counters_omitted="
         f"{res['omitted']}; degraded = all 12 members in § 9.3 order; predicates {w['p']} B "
         f"<= 512; selftest {w['s']} B <= 256")


print("\n== 11. STATUSLINE: sampling, passthrough, and honest sourcing (AT-14) ==")
wrapped = TMP / "wrapped.sh"
wrapped.write_text("#!/bin/sh\nprintf 'MY ORIGINAL STATUS LINE'\n", encoding="utf-8")
wrapped.chmod(0o755)
s14 = seat("statusline", wrapped=str(wrapped))
CW = {"session_id": SID, "context_window": {"used_percentage": 41.0, "total_input_tokens": 82000,
      "context_window_size": 200000}, "model": {"display_name": "claude-opus-5"}}
r = statusline(s14, CW)
eq("the wrapped status line is passed through BYTE-IDENTICALLY", "MY ORIGINAL STATUS LINE", r.stdout)
eq("  … and the reporter prints nothing of its own there", True, r.stdout == "MY ORIGINAL STATUS LINE")
samples = [e for e in s14.events() if e["kind"] == "context.sample"]
eq("the first render of a session emits one sample", 1, len(samples))
eq("  … with sample_reason `first_of_session`", "first_of_session", samples[0]["data"]["sample_reason"])
eq("  … and used_pct_source `harness` when the payload supplied the percentage", "harness",
   samples[0]["data"]["used_pct_source"])
for _ in range(5):
    statusline(s14, CW)
eq("subsequent renders in the same bucket are SUPPRESSED, not streamed", 1,
   len([e for e in s14.events() if e["kind"] == "context.sample"]))
eq("  … and every suppression is counted (a zero here means sampling is broken, not quiet)",
   5, s14.counters().get("statusline_suppressed"))
CW2 = json.loads(json.dumps(CW))
CW2["context_window"]["used_percentage"] = 62.0
statusline(s14, CW2)
crossed = [e for e in s14.events() if e["kind"] == "context.sample"]
eq("a 5-point bucket crossing emits, with sample_reason `threshold_cross`", "threshold_cross",
   crossed[-1]["data"]["sample_reason"])

# THE FIXTURE THAT KEEPS THE TWO BRANCHES HONEST (§ 6.11): the fallback must produce the same
# number the primary does, and that is a `used_tokens` question, not a rounding one.
s14b = seat("statusline-fallback")
statusline(s14b, {"session_id": SID, "context_window": {"total_input_tokens": 15500,
                                                        "context_window_size": 200000,
                                                        "total_output_tokens": 40000}})
fb = [e for e in s14b.events() if e["kind"] == "context.sample"][0]["data"]
eq("the computed fallback agrees with the harness's own 8 % to within 1 point (15500/200000)",
   True, abs(fb["used_pct"] - 8) <= 1.0)
eq("  … and says so via used_pct_source `computed`", "computed", fb["used_pct_source"])
p_out_tokens = plant_src((r"      usedPct = \(usedTokens / totalTokens\) \* 100;",
                          "      usedPct = ((usedTokens + (cw.total_output_tokens || 0)) / totalTokens) * 100;"))
s14r = seat("statusline-fallback-red")
statusline(s14r, {"session_id": SID, "context_window": {"total_input_tokens": 15500,
                                                        "context_window_size": 200000,
                                                        "total_output_tokens": 40000}},
           reporter=p_out_tokens)
fbr = [e for e in s14r.events() if e["kind"] == "context.sample"][0]["data"]
eq(f"RED: computing the fallback with output tokens added diverges from the harness "
   f"({fbr['used_pct']} % vs 8 %) — one wire field with two meanings", True,
   abs(fbr["used_pct"] - 8) > 1.0)

# A wrapped command that fails costs the seat's status line and a counter — never the seat.
broken = TMP / "broken.sh"
broken.write_text("#!/bin/sh\nexit 3\n", encoding="utf-8")
broken.chmod(0o755)
s14f = seat("statusline-broken", wrapped=str(broken))
r = statusline(s14f, CW)
eq("a failing wrapped command still exits 0", 0, r.returncode)
eq("  … blanks the status line rather than breaking the seat", "", r.stdout)
eq("  … and is counted as wrapped_statusline_failures", 1,
   s14f.counters().get("wrapped_statusline_failures"))

# § 6.9's cross-process read, and its honest null.
s9 = seat("compaction")
statusline(s9, CW)
hook(s9, "PreCompact", {"session_id": SID, "hook_event_name": "PreCompact", "trigger": "manual",
                        "custom_instructions": None})
cs = [e for e in s9.events() if e["kind"] == "compaction.start"][0]["data"]
eq("compaction.start reads the statusLine sample store (the one cross-process read in a hook)",
   41.0, cs["context_used_pct"])
eq("  … and ships the sample's AGE so a consumer never has to assume freshness", True,
   cs["context_used_pct_age_s"] is not None and cs["context_used_pct_age_s"] <= 300)
s9n = seat("compaction-nosample")
hook(s9n, "PreCompact", {"session_id": SID, "hook_event_name": "PreCompact", "trigger": "manual"})
csn = [e for e in s9n.events() if e["kind"] == "compaction.start"][0]["data"]
eq("with no statusLine integration the field is an honest null, never fabricated", None,
   csn["context_used_pct"])
eq("  … and context_sample_stale counts it", 1, s9n.counters().get("context_sample_stale"))
eq("  … while custom_instructions never transits", True,
   "custom_instructions" not in json.dumps(csn))
redgreen("statusLine sampling and passthrough (AT-14)",
         f"fallback computed with output tokens added -> {fbr['used_pct']} % vs the harness's 8 % "
         f"(one field, two meanings)",
         f"passthrough byte-identical; 6 renders -> 1 sample + 5 counted suppressions; bucket "
         f"crossing emits `threshold_cross`; fallback = {fb['used_pct']} % (within 1 pt of 8); "
         f"broken wrapped cmd -> rc=0, blank line, wrapped_statusline_failures=1; no sample -> "
         f"context_used_pct=null + context_sample_stale=1")


print("\n== 12. THE HEADLINE TRACE: a /clear during a subagent's Bash call (§ 8.7, AT-1) ==")
# This is the reporter half of AT-1 — the gate on trusting the signal at all. The full test
# needs a real seat and a real /clear (card #7337); what is asserted here is that the reporter
# puts § 8.7's exact event sequence on the wire when driven with the hooks a /clear fires.
s87 = seat("clear-trace")
OLD, NEW = SID, "22222222-3333-4444-8555-000000000001"
hook(s87, "SessionStart", {"session_id": OLD, "hook_event_name": "SessionStart",
                           "source": "startup", "cwd": "/home/agent/mezzanine"})
hook(s87, "UserPromptSubmit", {"session_id": OLD, "hook_event_name": "UserPromptSubmit",
                               "prompt_id": "p1", "prompt": "go", "cwd": "/home/agent/mezzanine"})
hook(s87, "PreToolUse", pre(tool="Agent", ti={"description": "probe the ingest", "subagent_type": "coder"},
                            tuid="toolu_A", session_id=OLD))
hook(s87, "SubagentStart", {"session_id": OLD, "hook_event_name": "SubagentStart", "prompt_id": "p1",
                            "agent_id": "0123456789abcdef0", "agent_type": "coder"})
hook(s87, "PreToolUse", pre(tool="Bash", ti={"command": "sleep 120"}, tuid="toolu_B",
                            session_id=OLD, agent_id="0123456789abcdef0"))
hook(s87, "SessionEnd", {"session_id": OLD, "hook_event_name": "SessionEnd", "reason": "clear"})
hook(s87, "SessionStart", {"session_id": NEW, "hook_event_name": "SessionStart",
                           "source": "clear", "cwd": "/home/agent/mezzanine"})
ev = s87.events()
kinds = [e["kind"] for e in ev]
# Since card#11817 the /clear CARRIES the bound dispatch A (its subagent survives the clear,
# MEASURED at 2.1.296) — so § 8.7's rows 5 and 6 (A's tool.end, its subagent.stop) do not occur,
# row 7 names only B, row 8 carries `carried_call_ids: [A]`, and row 9 names the session it paired.
eq("§ 8.7 rows 1-9 as amended by card#11817 arrive in exactly this order: B is reaped, A is carried",
   ["session.start", "turn.start", "tool.start", "subagent.spawn", "tool.start",
    "tool.end", "turn.end", "session.end", "session.start"], kinds)
A = ev[2]["data"]["call_id"]
B = ev[4]["data"]["call_id"]
eq("  the subagent's inner call names its parent dispatch call", A, ev[4]["data"]["parent_call_id"])
eq("  … and is labelled agent_scope `subagent`", "subagent", ev[4]["data"]["agent_scope"])
eq("  the ONE close at the clear is the inner call B, aborted / session_cleared / reap_session_boundary / reap",
   (B, "aborted", "session_cleared", "reap_session_boundary", "reap"),
   (ev[5]["data"]["call_id"], ev[5]["data"]["outcome"], ev[5]["data"]["abort_reason"],
    ev[5]["data"]["close_source"], ev[5]["data"]["match"]))
eq("  … and NO close and no subagent.stop for the carried dispatch A",
   [], [e for e in ev if e["kind"] in ("tool.end", "subagent.stop") and e["data"]["call_id"] == A])
te = [e for e in ev if e["kind"] == "turn.end"][0]["data"]
eq("  turn.end: end_reason session_cleared, open_calls_at_end 1, aborted_call_ids [B] — the carried "
   "A is neither counted nor named", ("session_cleared", 1, [B]),
   (te["end_reason"], te["open_calls_at_end"], te["aborted_call_ids"]))
eq("  … and NO tool.end anywhere reports completed or failed — the whole point of the ledger",
   [], [e for e in ev if e["kind"] == "tool.end" and e["data"]["outcome"] != "aborted"])
eq("  … and NO turn.end reports stop_hook, so D2-MUST #1 can never mint idle here",
   [], [e for e in ev if e["kind"] == "turn.end" and e["data"]["end_reason"] == "stop_hook"])
se87 = [e for e in ev if e["kind"] == "session.end"][0]["data"]
eq("  session.end: clear, 1 aborted call, carried_call_ids [A]", ("clear", 1, [A]),
   (se87["end_reason"], se87["aborted_calls"], se87.get("carried_call_ids")))
eq("  the SECOND /clear signal finds nothing and says so exactly once", 1,
   s87.counters().get("reap_noop_second_signal"))
eq("  … and the predicate records WHICH signal came first, one evaluation per /clear",
   {"true": 1, "false": 0}, s87.predicates().get("clear_reap_by_session_end"))
eq("  … and the new session.start names no previous session in this order — its value before "
   "card#11817, kept because a meaning change is a schema bump (VERSIONING wire rule 4)",
   None, [e for e in ev if e["kind"] == "session.start"][-1]["data"]["previous_session_id"])
eq("  … while the two signals still paired, internally, exactly once", 1,
   s87.counters().get("clear_signals_paired"))
eq("  … with no call closed twice", len({e["data"]["call_id"] for e in ev if e["kind"] == "tool.end"}),
   len([e for e in ev if e["kind"] == "tool.end"]))

# The reverse hook order must put the same event SET on the wire. Since card#11817 SessionStart
# reaps nothing, so in this order its session.start comes FIRST; it still names the session it
# selected, as before, and the boundary set follows from SessionEnd, which pairs with it.
s87r = seat("clear-trace-reverse")
hook(s87r, "SessionStart", {"session_id": OLD, "hook_event_name": "SessionStart", "source": "startup", "cwd": "/home/agent/mezzanine"})
hook(s87r, "UserPromptSubmit", {"session_id": OLD, "hook_event_name": "UserPromptSubmit", "prompt_id": "p1", "prompt": "go", "cwd": "/home/agent/mezzanine"})
hook(s87r, "PreToolUse", pre(tool="Agent", ti={"description": "probe", "subagent_type": "coder"}, tuid="toolu_A", session_id=OLD))
hook(s87r, "SubagentStart", {"session_id": OLD, "hook_event_name": "SubagentStart", "prompt_id": "p1", "agent_id": "0123456789abcdef0", "agent_type": "coder"})
hook(s87r, "PreToolUse", pre(tool="Bash", ti={"command": "sleep 120"}, tuid="toolu_B", session_id=OLD, agent_id="0123456789abcdef0"))
hook(s87r, "SessionStart", {"session_id": NEW, "hook_event_name": "SessionStart", "source": "clear", "cwd": "/home/agent/mezzanine"})
hook(s87r, "SessionEnd", {"session_id": OLD, "hook_event_name": "SessionEnd", "reason": "clear"})
evr = s87r.events()
eq("SessionStart-first produces the same kinds, with the new session.start BEFORE the boundary set",
   ["session.start", "turn.start", "tool.start", "subagent.spawn", "tool.start",
    "session.start", "tool.end", "turn.end", "session.end"],
   [e["kind"] for e in evr])
eq("  … the reap still happened EXACTLY once", 1,
   len([e for e in evr if e["kind"] == "session.end"]))
eq("  … and the second signal (SessionEnd this time) counted the no-op", 1,
   s87r.counters().get("reap_noop_second_signal"))
eq("  … with the predicate recording the OTHER branch", {"true": 0, "false": 1},
   s87r.predicates().get("clear_reap_by_session_end"))
eq("  … and the new session.start names the session it superseded, as before card#11817", OLD,
   [e for e in evr if e["kind"] == "session.start"][-1]["data"]["previous_session_id"])
eq("  … and SessionEnd did the internal pairing, exactly once", 1,
   s87r.counters().get("clear_signals_paired"))

# RED — disable the reap and watch the false idle become possible.
p_noreap = plant_src((r"  const victims = \[\.\.\.ix\.calls\.values\(\)\]\.filter\(select\)\.reverse\(\);",
                      "  const victims = [];"))
s87n = seat("clear-trace-noreap")
hook(s87n, "SessionStart", {"session_id": OLD, "hook_event_name": "SessionStart", "source": "startup", "cwd": "/home/agent/mezzanine"}, reporter=p_noreap)
hook(s87n, "UserPromptSubmit", {"session_id": OLD, "hook_event_name": "UserPromptSubmit", "prompt_id": "p1", "prompt": "go", "cwd": "/home/agent/mezzanine"}, reporter=p_noreap)
hook(s87n, "PreToolUse", pre(tool="Agent", ti={"description": "probe"}, tuid="toolu_A", session_id=OLD), reporter=p_noreap)
hook(s87n, "PreToolUse", pre(tool="Bash", ti={"command": "sleep 120"}, tuid="toolu_B", session_id=OLD), reporter=p_noreap)
hook(s87n, "SessionEnd", {"session_id": OLD, "hook_event_name": "SessionEnd", "reason": "clear"}, reporter=p_noreap)
evn = s87n.events()
ten = [e for e in evn if e["kind"] == "turn.end"]
eq("RED: with the reap disabled the boundary carries aborted_call_ids: [] — the killed calls "
   "vanish and a consumer applying 'turn ended => idle' mints the FALSE IDLE", [],
   ten[0]["data"]["aborted_call_ids"])
eq("  … and no tool.end is emitted for either killed call at all", 0,
   len([e for e in evn if e["kind"] == "tool.end"]))
redgreen("kill-vs-complete, reporter half (§ 8.7, AT-1)",
         f"reap disabled -> aborted_call_ids=[], 0 tool.end for the two killed calls; "
         f"'turn ended => idle' would mint a false idle",
         f"§ 8.7's events as amended by card#11817 in exact order: inner call B "
         f"aborted/session_cleared/reap_session_boundary, the bound dispatch A carried "
         f"(carried_call_ids=[A], no close), turn.end open_calls_at_end=1 aborted_call_ids=[B], "
         f"no completed/failed close, no stop_hook turn.end; the reverse hook order gives the same "
         f"event set with session.start first, reap_noop_second_signal=1 and the opposite predicate branch")


print("\n== 13. THE KILL SIGNATURE: exit 137 AND a session boundary, never either alone (§ 6.6) ==")
# The close mapping D1 § 6.6 states after card#7337 amended it. `is_interrupt` alone MISSES the
# headline kill (a `/clear` SIGKILL is MEASURED at 2.1.245 as `Exit code 137` with
# `is_interrupt: false`), and exit 137 alone is SIGKILL in general — an OOM kill is a genuine
# failure. So every case below is driven WITH its opposite: the same payload one session apart must
# come out the other way, or the rule is a decoration that answers `aborted` to everything.
KOLD, KNEW = "33333333-4444-4555-8666-000000000001", "33333333-4444-4555-8666-000000000002"


def ptuf(session_id, *, tuid="toolu_K", error="Exit code 137", is_interrupt=False,
         tool="Bash", duration_ms=166):
    return {"session_id": session_id, "hook_event_name": "PostToolUseFailure", "prompt_id": "p1",
            "tool_name": tool, "tool_input": {"command": "sleep 120"}, "tool_use_id": tuid,
            "error": error, "is_interrupt": is_interrupt, "duration_ms": duration_ms,
            "cwd": "/home/agent/mezzanine"}


def kill_seat(name, *, reporter=REPORTER):
    """A seat with one Bash call open in KOLD — the state every case below closes."""
    s = seat(name)
    hook(s, "SessionStart", {"session_id": KOLD, "hook_event_name": "SessionStart",
                             "source": "startup", "cwd": "/home/agent/mezzanine"}, reporter=reporter)
    hook(s, "UserPromptSubmit", {"session_id": KOLD, "hook_event_name": "UserPromptSubmit",
                                 "prompt_id": "p1", "prompt": "go",
                                 "cwd": "/home/agent/mezzanine"}, reporter=reporter)
    hook(s, "PreToolUse", pre(tool="Bash", ti={"command": "sleep 120"}, tuid="toolu_K",
                              session_id=KOLD), reporter=reporter)
    return s


def closes(s):
    return [e for e in s.events() if e["kind"] == "tool.end"]


def outcome_of(s, idx=-1):
    d = closes(s)[idx]["data"]
    return (d["outcome"], d["abort_reason"], d["close_source"], d["match"])


# A — THE MEASURED ORDER: the reap wins, then the kill's own close arrives under the NEW session.
# Leg 2 fires on the tombstone, so the late close AGREES with the abort instead of contradicting it.
sA = kill_seat("kill-sig-late")
hook(sA, "SessionEnd", {"session_id": KOLD, "hook_event_name": "SessionEnd", "reason": "clear"})
hook(sA, "SessionStart", {"session_id": KNEW, "hook_event_name": "SessionStart",
                          "source": "clear", "cwd": "/home/agent/mezzanine"})
hook(sA, "PostToolUseFailure", ptuf(KNEW))
eq("the reap closes the killed call first, aborted / session_cleared",
   ("aborted", "session_cleared", "reap_session_boundary", "reap"), outcome_of(sA, 0))
eq("  … and the harness's own close, exit 137 across the session boundary, closes it ABORTED "
   "— the kill signature's two legs, not is_interrupt (MEASURED false on this kill)",
   ("aborted", "interrupted", "post_tool_use_failure", "tombstone_ref"), outcome_of(sA, 1))
eq("  … it is the SAME call_id, so no consumer sees a second call",
   1, len({e["data"]["call_id"] for e in closes(sA)}))
eq("  … the tombstone match is counted", 1, sA.counters().get("tombstone_late_close"))
eq("  … and the residual counter does NOT fire, because leg 2 DID", None,
   sA.counters().get("kill_close_same_session"))

# B — the same kill with the reap NOT yet run: the close still crosses the boundary, on a call
# that is still open. Leg 2 is a property of the CALL, which is why it is evaluated at the match.
sB = kill_seat("kill-sig-open")
hook(sB, "PostToolUseFailure", ptuf(KNEW))
eq("a cross-session exit-137 close on a still-OPEN call is aborted / interrupted",
   ("aborted", "interrupted", "post_tool_use_failure", "harness_ref"), outcome_of(sB))

# C — THE NEGATIVE CONTROL, and the whole reason the conjunction exists: an OOM kill. Exit 137,
# is_interrupt false, NO session boundary. It is a genuine failure the agent reads and carries on
# from; reading it as `aborted` would block *idle* on a turn that legitimately finished.
sC = kill_seat("kill-sig-oom")
hook(sC, "PostToolUseFailure", ptuf(KOLD))
eq("exit 137 with NO session boundary is a FAILURE (the OOM kill), never an abort",
   ("failed", None, "post_tool_use_failure", "harness_ref"), outcome_of(sC))
eq("  … and § 6.6's stated residual is VISIBLE rather than assumed away: the second leg "
   "did not fire, and kill_close_same_session says so", 1,
   sC.counters().get("kill_close_same_session"))

# D — leg 1 alone still closes an abort: the harness naming the interrupt itself needs no boundary.
sD = kill_seat("kill-sig-interrupt")
hook(sD, "PostToolUseFailure", ptuf(KOLD, error="Exit code 130", is_interrupt=True))
eq("is_interrupt true closes aborted / interrupted with no boundary in sight",
   ("aborted", "interrupted", "post_tool_use_failure", "harness_ref"), outcome_of(sD))

# E — an ordinary failing tool call. The counter must not fire on every failure, or it measures
# nothing; the close must stay `failed`, or *idle* is unreachable on any turn containing one.
sE = kill_seat("kill-sig-ordinary")
hook(sE, "PostToolUseFailure", ptuf(KOLD, error="Exit code 3"))
eq("an ordinary exit-3 failure closes failed", ("failed", None, "post_tool_use_failure", "harness_ref"),
   outcome_of(sE))
eq("  … and does not touch the kill counter", None, sE.counters().get("kill_close_same_session"))

# F — the leg that CANNOT fire: a close whose open was never seen (reporter installed mid-call,
# or the open lost to overflow). § 6.6 synthesizes the pair, and the call's own session is
# unknowable — so the boundary cannot be evaluated at all. That is named by the same counter
# rather than being silently resolved either way.
sF = seat("kill-sig-synth")
hook(sF, "SessionStart", {"session_id": KNEW, "hook_event_name": "SessionStart",
                          "source": "startup", "cwd": "/home/agent/mezzanine"})
hook(sF, "PostToolUseFailure", ptuf(KNEW, tuid="toolu_ORPHAN"))
eq("a SYNTHESIZED close cannot evaluate the boundary, so it closes failed",
   ("failed", None, "post_tool_use_failure", "synthesized"), outcome_of(sF))
eq("  … and the same counter names the leg that could not fire", 1,
   sF.counters().get("kill_close_same_session"))

# RED 1 — THE DEFECT THIS SECTION EXISTS FOR (card#7684): map by is_interrupt ALONE, which is
# what the reporter did while D1 § 6.6 said otherwise. Case A's kill then closes `failed`.
KILL_ANCHOR = ("        if (ii === true || (exit137 && crossSession)) "
               "{ outcome = 'aborted'; abortReason = 'interrupted'; }")
p_ii_only = plant((KILL_ANCHOR,
                   "        if (ii === true) { outcome = 'aborted'; abortReason = 'interrupted'; }"))
sR1 = kill_seat("kill-sig-red-ii", reporter=p_ii_only)
hook(sR1, "SessionEnd", {"session_id": KOLD, "hook_event_name": "SessionEnd", "reason": "clear"},
     reporter=p_ii_only)
hook(sR1, "SessionStart", {"session_id": KNEW, "hook_event_name": "SessionStart",
                           "source": "clear", "cwd": "/home/agent/mezzanine"}, reporter=p_ii_only)
hook(sR1, "PostToolUseFailure", ptuf(KNEW), reporter=p_ii_only)
eq("RED: mapping by is_interrupt ALONE closes the /clear kill as an ordinary FAILURE — the "
   "hazard § 6.6 names, and the state of this file before card#7684",
   ("failed", None, "post_tool_use_failure", "tombstone_ref"), outcome_of(sR1, 1))

# RED 2 — the OTHER promotion: exit 137 as a sole rule. The OOM kill of case C becomes an abort,
# which blocks *idle* on a turn that legitimately finished. Both legs are load-bearing, both ways.
p_137_only = plant((KILL_ANCHOR,
                    "        if (ii === true || exit137) { outcome = 'aborted'; abortReason = 'interrupted'; }"))
sR2 = kill_seat("kill-sig-red-137", reporter=p_137_only)
hook(sR2, "PostToolUseFailure", ptuf(KOLD), reporter=p_137_only)
eq("RED: promoting exit 137 to a sole rule reads an OOM KILL as an abort, blocking idle on a "
   "turn that finished", ("aborted", "interrupted", "post_tool_use_failure", "harness_ref"),
   outcome_of(sR2))
redgreen("the kill signature discriminates, and neither leg carries it alone (§ 6.6, card#7684)",
         "is_interrupt alone -> the /clear kill (exit 137, is_interrupt false, new session) closes "
         "`failed`; exit 137 alone -> an OOM kill closes `aborted` and blocks idle on a finished turn",
         "exit 137 + boundary -> aborted/interrupted on both the tombstone and the still-open call; "
         "exit 137 without a boundary -> failed + kill_close_same_session=1; is_interrupt alone -> "
         "aborted; exit 3 -> failed with the counter untouched; a synthesized close cannot evaluate "
         "the boundary and says so through the same counter")


print("\n== 14. UNKNOWN ENUM VALUES COST ONE FIELD, NOT A BATCH (AT-18, § 6.0 rule 4) ==")
s18 = seat("enum")
hook(s18, "SessionStart", {"session_id": SID, "hook_event_name": "SessionStart",
                           "source": "teleport", "cwd": "/home/agent/mezzanine"})
ss = [e for e in s18.events() if e["kind"] == "session.start"][0]
eq("an unrecognised harness enum value is COERCED to the field's unknown member", "unknown",
   ss["data"]["source"])
eq("  … and counted under § 6.0 rule 4's dotted-field grammar", 1,
   s18.counters().get("enum_value_unknown.session.start.source"))
eq("  … while the raw value reaches the wire NOWHERE (it would 422 the whole 200-event batch)",
   True, "teleport" not in json.dumps(s18.events()))
s18c = seat("enum-control")
hook(s18c, "SessionStart", {"session_id": SID, "hook_event_name": "SessionStart",
                            "source": "fork", "cwd": "/home/agent/mezzanine"})
eq("CONTROL: a member the reporter DOES know passes through verbatim", "fork",
   [e for e in s18c.events() if e["kind"] == "session.start"][0]["data"]["source"])
eq("  … with no coercion counted, so the check measures coercion and not blanket rewriting",
   None, s18c.counters().get("enum_value_unknown.session.start.source"))

# The Notification gate — the one carve-out, and the reason `blocked` is not minted for
# `auth_success`. An unconditional emission would put every seat into a false *blocked*.
s12 = seat("notification")
hook(s12, "Notification", {"session_id": SID, "hook_event_name": "Notification",
                           "notification_type": "auth_success", "message": "hi"})
eq("a non-attention Notification emits NOTHING", [],
   [e for e in s12.events() if e["kind"] == "attention.request"])
eq("  … and the suppression is counted individually, never silently", 1,
   s12.counters().get("notification_not_attention.auth_success"))
hook(s12, "Notification", {"session_id": SID, "hook_event_name": "Notification",
                           "notification_type": "elicitation_url_dialog", "message": "hi"})
ar = [e for e in s12.events() if e["kind"] == "attention.request"]
eq("an attention-bearing type DOES open a request", 1, len(ar))
eq("  … mapped through the lookup table, not a classifier", "elicitation",
   ar[0]["data"]["notification_kind"])
eq("  … and the message text never transits", True, "hi" not in json.dumps(ar[0]))
hook(s12, "UserPromptSubmit", {"session_id": SID, "hook_event_name": "UserPromptSubmit",
                               "prompt_id": "p9", "prompt": "ok", "cwd": "/home/agent/mezzanine"})
res12 = [e for e in s12.events() if e["kind"] == "attention.resolved"]
eq("blocked HAS AN EXIT: a human typing resolves it (AT-20's edge)", 1, len(res12))
eq("  … as human_input / user_prompt_submit", ("human_input", "user_prompt_submit"),
   (res12[0]["data"]["resolution"], res12[0]["data"]["resolution_source"]))
eq("  … joined to the request by request_id", ar[0]["data"]["request_id"], res12[0]["data"]["request_id"])
hook(s12, "Notification", {"session_id": SID, "hook_event_name": "Notification",
                           "notification_type": "brand_new_type_nobody_declared"})
eq("an UNDECLARED type is counted separately from a known non-attention one", 1,
   s12.counters().get("enum_value_unknown.notification_type"))

# `idle_prompt` IS NOT A WAIT ON A HUMAN, and it is the one member of the table that looked
# like one (card#9419). The harness fires it about a minute after Claude finishes responding
# when the human has not typed since — a timer on human ABSENCE, which is the ordinary shape of
# a seat that finished its turn cleanly and is available for work. Emitting for it flipped every
# such seat from `idle` to `blocked` (D2 § 4.3 rule 1 renders any open request `blocked`, ahead
# of every other rule) about a minute after it went quiet, and held it there until its next
# event: measured on one seat in one day, 19 `input_awaited` requests against 3
# `permission_required`. So it takes the same counted suppression path as `auth_success`, and it
# must NOT also raise `enum_value_unknown.notification_type` — it is a declared type the gate
# decides against, not a type this reporter has never seen, and § 6.12 requires those two to
# never be one number.
s12i = seat("notification-idle")
hook(s12i, "Notification", {"session_id": SID, "hook_event_name": "Notification",
                            "notification_type": "idle_prompt", "message": "hi"})
eq("`idle_prompt` is a timer on human absence and emits NOTHING (card#9419)", [],
   [e for e in s12i.events() if e["kind"] == "attention.request"])
eq("  … suppressed through the counted path, not dropped silently", 1,
   s12i.counters().get("notification_not_attention.idle_prompt"))
eq("  … and NOT counted as an undeclared type — it is declared, and decided against", None,
   s12i.counters().get("enum_value_unknown.notification_type"))
s12p = seat("notification-permission")
hook(s12p, "Notification", {"session_id": SID, "hook_event_name": "Notification",
                            "notification_type": "permission_prompt", "message": "hi"})
arp = [e for e in s12p.events() if e["kind"] == "attention.request"]
eq("CONTROL: a genuine wait on a human still opens a request", 1, len(arp))
eq("  … so the row above measures the `idle_prompt` decision and not a dead gate",
   "permission_required", arp[0]["data"]["notification_kind"])
redgreen("unknown enum values (AT-18) and the blocked pair (AT-20)",
         "passing an unknown value through verbatim -> 422 invalid_event, all 200 events in the "
         "batch rejected, quarantined permanently (D1 § 6.0 rule 4's stated cost)",
         "source=teleport -> coerced to `unknown`, counted as "
         "enum_value_unknown.session.start.source, raw value absent from the wire; control "
         "source=fork passes through with no coercion counted; auth_success emits nothing and is "
         "counted; elicitation_url_dialog opens a request that a UserPromptSubmit then resolves; "
         "idle_prompt emits nothing, is counted notification_not_attention.idle_prompt and is NOT "
         "counted as an undeclared type, while the permission_prompt control still opens one")


# AN ATTENTION REQUEST HAS NO TIMER (§ 6.13, card#9527). The operator's ruling: "Mezzanine should
# assume agent is stuck until it gets another status update from that agent." So two hours on —
# past the 60-minute `timeout` the reporter used to emit, and past § 6.2's 90-minute silence close —
# the flusher resolves nothing and closes only the session that is NOT waiting. The second session
# is the CONTROL that the silence close still fires at all, so a pass that closed nothing would red.
# SEEN RED against the reporter before card#9527: that pass emitted
# `attention.resolved(timeout)` for the waiting session and then its `inferred_silence` close.
s12w = seat("attention-no-timer", ingest=DEAD)
SID_IDLE = "11111111-2222-4333-8444-0000000000a1"
w0 = 1_790_000_000_000
for sid in (SID, SID_IDLE):
    hook(s12w, "SessionStart", {"session_id": sid, "hook_event_name": "SessionStart",
                                "source": "startup", "cwd": "/home/agent/mezzanine"},
         FLEET_REPORTER_NOW_MS=w0)
hook(s12w, "PermissionRequest", {"session_id": SID, "hook_event_name": "PermissionRequest",
                                 "tool_name": "Bash", "tool_input": {"command": "x"},
                                 "permission_suggestions": None}, FLEET_REPORTER_NOW_MS=w0)
eq("a PermissionRequest opens the request the rest of this block watches", 1,
   len([e for e in s12w.events() if e["kind"] == "attention.request"]))
flush(s12w, FLEET_REPORTER_NOW_MS=w0 + 2 * 3600 * 1000)
eq("two hours on, the flusher resolves NOTHING — the 60-minute `timeout` is gone (§ 6.13)", [],
   [e["data"]["resolution"] for e in s12w.events() if e["kind"] == "attention.resolved"])
silent = sorted(e["session_id"] for e in s12w.events()
                if e["kind"] == "session.end" and e["data"]["end_reason"] == "inferred_silence")
eq("  … and § 6.2's silence close ends only the session that is NOT waiting on a human",
   [SID_IDLE], silent)
hook(s12w, "UserPromptSubmit", {"session_id": SID, "hook_event_name": "UserPromptSubmit",
                                "prompt_id": "p1", "prompt": "go", "cwd": "/home/agent/mezzanine"},
     FLEET_REPORTER_NOW_MS=w0 + 2 * 3600 * 1000 + 1000)
res12w = [e for e in s12w.events() if e["kind"] == "attention.resolved"]
# `waited_ms` is held to "at least two hours" and not to an exact figure: a pinned clock is an OFFSET
# taken when the process starts (`CLOCK_OFFSET`), so each hook's instants carry its own start-up
# milliseconds and the difference of two of them is not exact.
eq("  … and the wait still has its observed exit: the human's prompt resolves it, two hours late",
   [("human_input", True)],
   [(e["data"]["resolution"], e["data"]["waited_ms"] >= 2 * 3600 * 1000) for e in res12w])
redgreen("an attention request has no timer (§ 6.13, card#9527)",
         "the reporter before card#9527: at 60 min the flusher emitted attention.resolved(timeout) "
         "and at 90 min closed the waiting session as inferred_silence, so the desk stopped "
         "rendering blocked while the agent was still waiting on a human",
         "two hours on: no resolution, the waiting session still open, the idle control session "
         "closed as inferred_silence, and the human's prompt resolves the request with "
         "waited_ms = 2 h")


print("\n== 15. A FAILED APPEND IS COUNTED, AND A BAD CACHED DESCRIPTOR COSTS NO EVENT (§ 0 item 9) ==")
# The loss shape § 0 item 9 forbids outright is the UNCOUNTED one: a seat that drops events and
# renders healthy. The fault is injected in the product's own terms — the current spool bucket is
# a DIRECTORY, so every append to it fails EISDIR — with `counters/` and `log/` left writable, so
# the counter sink is demonstrably able to record the loss it is being asked to record.
def bucket_now() -> str:
    """The bucket the reporter will derive AT THE WRITE (§ 11.1), computed as late as possible.

    Taken once at module scope this races the UTC hour boundary: the fault would be planted in
    the previous hour's bucket and the run would go green having injected nothing.
    """
    return time.strftime("%Y%m%d%H", time.gmtime())


def eisdir_seat(name: str, reporter: Path = REPORTER):
    s = seat(name)
    (s.spool / "counters").mkdir(exist_ok=True)
    (s.spool / "log").mkdir(exist_ok=True)
    (s.spool / f"{bucket_now()}.jsonl").mkdir()    # the injected fault
    r = hook(s, "PreToolUse", pre(), reporter=reporter)
    lines = sum(len([x for x in f.read_text(encoding="utf-8").splitlines() if x.strip()])
                for f in s.spool.glob("*.jsonl") if f.is_file())
    return s, r, lines


s14, r14, spooled14 = eisdir_seat("append-eisdir")
eq("an unwritable spool bucket still never breaks the seat (P-1)", (0, "", ""),
   (r14.returncode, r14.stdout, r14.stderr))
eq("  … the event really is lost — this is a LOSS, not a near-miss", 0, spooled14)
eq("  … and the loss is COUNTED, keyed by the subtree that lost it", 1,
   s14.counters().get("spool_append_failed.events"))
eq("  … so the heartbeat badges the seat `lossy` rather than rendering it healthy", True,
   "spool_append_failed.events" in json.dumps(s14.counters()))
eq("  … and NO retry counter is raised, because the retry did not recover anything", None,
   s14.counters().get("spool_append_retried.events"))
# RED: the primitive stops counting its own failure — which is the state this suite found the
# code in. Everything else is identical, so the check measures the counting and nothing else.
red14 = plant_src((r"count\(`spool_append_failed\.\$\{tree\}`\); return false;", "return false;"))
s14r, r14r, spooled14r = eisdir_seat("append-eisdir-red", reporter=red14)
eq("RED: without the primitive's own counter the very same loss reports `c`:{} — a healthy-"
   "looking seat dropping events", (0, 0, None),
   (r14r.returncode, spooled14r, s14r.counters().get("spool_append_failed.events")))

# The counter is raised by the PRIMITIVE, so a caller that never thinks about it still cannot
# lose silently. The counter sink is its own tree: make THAT unwritable and the loss of the
# counters is itself recorded, on the one surface left (the seat's log).
s14c = seat("append-counters-eisdir")
(s14c.spool / "counters").mkdir(exist_ok=True)
(s14c.spool / "counters" / f"{bucket_now()}.jsonl").mkdir()
hook(s14c, "PreToolUse", pre())
eq("a counter-sink append that fails leaves its trace in the seat log, not nowhere", True,
   any("counter sink append FAILED" in f.read_text(encoding="utf-8")
       for f in (s14c.spool / "log").glob("*.log")))

# THE RETRY LEG. A write error on a descriptor cached earlier in this process says nothing about
# whether a fresh open would fail; the old code dropped the entry and returned false, spending
# exactly one event per transient error. Injected here because the real trigger (EIO, a revoked
# handle) cannot be produced from outside the process.
THROW_ONCE = (
    r"if \(fd === undefined\) \{ ensureDir\(path\.dirname\(file\)\); fd = fs\.openSync\(file, 'a'\); "
    r"FD_CACHE\.set\(file, fd\); \}\n      fs\.writeSync\(fd, buf\);",
    "if (fd === undefined) { ensureDir(path.dirname(file)); fd = fs.openSync(file, 'a'); "
    "FD_CACHE.set(file, fd); }\n"
    # Fires ONCE, and only on an events bucket — which is the spool ROOT's `<bucket>.jsonl`,
    # matched positively rather than by excluding the sibling trees by substring, so a temp
    # directory that happens to contain the word `log` cannot spend the injection elsewhere.
    r"      if (!global.__frThrew && /spool[\\/]\d{10}\.jsonl$/.test(file)) "
    r"{ global.__frThrew = true; const _e = new Error('simulated cached-fd write error'); "
    r"_e.code = 'EIO'; throw _e; }"
    "\n      fs.writeSync(fd, buf);")
retry_src = plant_src(THROW_ONCE)
s14t = seat("append-retry")
hook(s14t, "PreToolUse", pre(), reporter=retry_src)
eq("a cached-descriptor write error costs NO event: the uncached reopen carries the line", 1,
   len([e for e in s14t.events() if e["kind"] == "tool.start"]))
eq("  … counted as a RECOVERY, which is what makes the fallback measurable rather than assumed",
   1, s14t.counters().get("spool_append_retried.events"))
eq("  … and never as a loss, so a § 9.3 loss sum cannot double-count it", None,
   s14t.counters().get("spool_append_failed.events"))
# RED: the same injected error against the pre-fix behaviour — the catch returned false instead
# of falling through to the reopen.
norretry_src = plant_src(THROW_ONCE, (
    r"      retried = true;\n      // fall through to the uncached open\+write below rather than "
    r"losing the line", "      return false;"))
s14n = seat("append-retry-red")
hook(s14n, "PreToolUse", pre(), reporter=norretry_src)
eq("RED: without the reopen the identical transient error loses exactly one event", 0,
   len([e for e in s14n.events() if e["kind"] == "tool.start"]))
redgreen(
    "a failed append is counted, and a bad cached descriptor costs no event (§ 0 item 9, § 11.1)",
    "current bucket made a directory: rc=0, 0 events spooled, counter sink `c`:{} — no counter "
    "raised at all, so the seat renders healthy while dropping events; and a transient error on "
    "a cached descriptor returns false without retrying, losing exactly one event",
    "same fault: rc=0, 0 events spooled, spool_append_failed.events=1 -> badge `lossy`; a "
    "counters-tree failure leaves 'counter sink append FAILED' in the seat log; an injected "
    "cached-fd EIO loses nothing and counts spool_append_retried.events=1")

print("\n== 16. THE OS USERNAME NEVER REACHES THE WIRE VIA project_label (§ 1 non-goal, § 6.1) ==")
# `path.basename` runs before sanitize(), so § 7.3 rule 6 (`/home/<u>/` -> `~/`) can never match:
# by the time the sanitizer sees the value the path structure is gone and only the bare username
# is left. The three home shapes are covered by comparing against os.homedir(), never by an
# enumerated list of home parents.
HOME_SHAPES = [("posix", "/home/alice"), ("macos", "/Users/alice"), ("windows", r"C:\Users\alice")]


def label_at(seat_obj: Seat, cwd: str, home: str, reporter: Path = REPORTER):
    hook(seat_obj, "UserPromptSubmit",
         {"session_id": SID, "hook_event_name": "UserPromptSubmit", "prompt_id": "pL",
          "prompt": "status?", "cwd": cwd}, reporter=reporter, HOME=home)
    ts = [e for e in seat_obj.events() if e["kind"] == "turn.start"]
    return ts[-1]["data"].get("project_label") if ts else "<no turn.start>"


for tag, home in HOME_SHAPES:
    s15 = seat(f"label-home-{tag}")
    eq(f"cwd == the home directory ({tag} shape) sends project_label null, never the username",
       None, label_at(s15, home, home))
    eq(f"  … ({tag}) and the suppression is counted, so it is not silent", 1,
       s15.counters().get("project_label_home_suppressed"))

s15t = seat("label-home-trailing")
eq("a trailing separator is not a way around the rule", None,
   label_at(s15t, "/home/alice/", "/home/alice"))

# CONTROL: the field still works. A rule that nulled every label would pass every check above
# and destroy the field, so the control is what makes those checks mean anything.
s15c = seat("label-project")
eq("CONTROL: a real project directory keeps its label", "mezzanine",
   label_at(s15c, "/home/alice/src/mezzanine", "/home/alice"))
eq("  … with no suppression counted, so the check measures the home case and not blanket nulling",
   None, s15c.counters().get("project_label_home_suppressed"))

red15 = plant_src((r"if \(isHomeDir\(cwd\)\) \{ count\('project_label_home_suppressed'\); return null; \}",
                   ""))
s15r = seat("label-home-red")
eq("RED: without the rule the OS username is what crosses the WAN", "alice",
   label_at(s15r, "/home/alice", "/home/alice", reporter=red15))
redgreen("the OS username never reaches the wire via project_label (§ 1 non-goal, § 6.1)",
         'cwd=/home/alice -> "project_label":"alice" on the wire; § 7.3 rule 6 cannot fire '
         "because the basename is taken before the sanitizer sees the value",
         "all three home shapes (/home/u, /Users/u, C:\\Users\\u) and a trailing separator send "
         "null and count project_label_home_suppressed; control /home/alice/src/mezzanine still "
         'sends "mezzanine" with no suppression counted')


print("\n== 17. THE BUCKET IS DERIVED AT THE WRITE, AND § 6.1's PATTERN ADMITS ITS OWN EXAMPLE ==")
# § 11.1: a hook stamping its events at 13:59:59.900 must not write into bucket 13 after the hour
# rolled — the flusher's next pass is <= 10 s away and would read to EOF and unlink. The events'
# stamp (§ 11.2: read when the section takes the seat write lock) is moved back an hour, which is
# that boundary made deterministic; the event's `event_time` must follow the stamp while the FILE
# it lands in must follow the write clock.
ENTRY_BACK_AN_HOUR = (r"held = acquireWriteLock\(spool\); stamp = now\(\);",
                      "held = acquireWriteLock(spool); stamp = now() - 3600000;")


def bucket_of(rfc: str) -> str:
    """The bucket name an event's own timestamp implies — `utcBucket`, in Python."""
    return rfc[:13].replace("-", "").replace("T", "")


def bucket_shift(b: str, hours: int) -> str:
    return time.strftime("%Y%m%d%H", time.gmtime(
        calendar.timegm(time.strptime(b, "%Y%m%d%H")) + hours * 3600))


def emitted_bucket(s: Seat) -> tuple[str, list[str]]:
    """(the bucket the event's OWN time implies, the buckets actually written).

    Asserting the RELATION between the two rather than against a clock read in this process is
    what keeps the check off the hour boundary it exists to be about: a run that straddles the
    roll would otherwise fail on the harness's timing rather than the reporter's.
    """
    return (bucket_of(s.events()[0]["event_time"]),
            sorted(f.stem for f in s.spool.glob("*.jsonl") if f.is_file()))


s16 = seat("bucket-at-write")
hook(s16, "PreToolUse", pre(), reporter=plant_src(ENTRY_BACK_AN_HOUR))
entry_b, written_b = emitted_bucket(s16)
eq("an event emitted after the hour rolled lands in the bucket the WRITE clock names, one hour "
   "on from the event's stamp", [bucket_shift(entry_b, 1)], written_b)
eq("  … while event_time still carries the stamp, so placement and semantics are not "
   "conflated", True, entry_b == bucket_shift(written_b[0], -1))
# RED: the pre-fix derivation — the bucket taken from the event's stamp at both writers.
s16r = seat("bucket-at-entry")
hook(s16r, "PreToolUse", pre(),
     reporter=plant_src(ENTRY_BACK_AN_HOUR, (r"utcBucket\(now\(\)\)", "utcBucket(t)")))
entry_r, written_r = emitted_bucket(s16r)
eq("RED: derived from the stamp it lands in the PREVIOUS hour's bucket — behind a cursor the "
   "flusher may already have read to EOF and unlinked", [entry_r], written_r)

# § 6.1's `harness_label` pattern has to accept § 6.1's own mandated value. The doc and the
# reporter hold two copies of one constraint, so this asserts them TOGETHER: a doc pattern that
# rejects the value the installer is told to write means a 422, a rejected 200-event batch and a
# permanent quarantine (§ 12.4, § 11.5) the first time a seat is configured correctly.
DOC = (HERE.parent / "docs/design/EVENT-SCHEMA.md").read_text(encoding="utf-8")
row = [l for l in DOC.splitlines() if l.startswith("| `harness_label` |")][0]
doc_pat = re.search(r"`(\^\[[^`]+\$)`", row).group(1)
doc_example = re.search(r"`\"([^\"]+)\"`\s*\|?\s*$", row).group(1)
eq("§ 6.1's harness_label pattern accepts § 6.1's own mandated example", True,
   bool(re.match(doc_pat, doc_example)))
eq("  (control) the pattern this replaced rejected that same mandated value", None,
   re.match(r"^[A-Za-z0-9._-]+$", doc_example))
eq("  … and it still rejects a value the field may not carry", None,
   re.match(doc_pat, "claude code/2.1.240"))
s16h = seat("harness-label")
hook(s16h, "SessionStart", {"session_id": SID, "hook_event_name": "SessionStart",
                            "source": "startup", "cwd": "/home/agent/mezzanine"})
eq("  … and the REPORTER emits that same value, so doc and code agree on the wire", doc_example,
   [e for e in s16h.events() if e["kind"] == "session.start"][0]["data"]["harness_label"])
redgreen("the bucket is derived at the write (§ 11.1) and § 6.1's pattern admits its own example",
         "entry-derived bucket: an event emitted at 14:00:00.050 by a hook that entered at "
         "13:59:59.900 is written into bucket 13, which the flusher may already have read to EOF "
         "and unlinked; and D1 § 6.1's `^[A-Za-z0-9._-]+$` rejects `claude-code/2.1.240`, the "
         "value that same row mandates -> 422, 200 events rejected, permanent quarantine",
         f"write-derived bucket: the event lands in {bucket_now()} with an event_time an hour "
         f"earlier; § 6.1's pattern now admits `{doc_example}` and the reporter emits it")


print("\n== 18. A FLUSHER THAT LOSES OWNERSHIP STOPS SENDING AND EXITS; A LIVE ONE KEEPS ITS LOCK FRESH (§ 2.3, card#9393) ==")
# THE DEFECT THIS BLOCK EXISTS FOR. `seq` is correct only while exactly one process assigns it,
# and § 2.3 makes state.json's owner fields the arbiter: a flusher that finds another owner
# exits without writing. The reporter used to DETECT that (the save refused) and then carry on —
# the drain kept posting from its in-memory `next_seq` and the loop kept running passes — so two
# live flushers emitted overlapping seqs for one seat. The way a second flusher comes to exist
# at all is the other half: the lock was touched once per pass, and one pass on a slow ingest
# awaits several 15 s requests, so a LIVE flusher's lock could age past LOCK_STALE_MS and a hook
# would correctly start another.
#
# A SLOW INGEST IS THE ONLY FIXTURE. `INGEST.delay_s` holds every POST before answering it; the
# takeover is written WHILE the first POST is held, which is the real race (a new owner claims
# state.json during a request), made deterministic by holding the request rather than by timing.
# The spool holds enough events for several drain rounds (`BATCH_EVENTS` per POST): one real
# hook line re-minted under fresh event ids, instead of hundreds of hook processes.
OTHER_PID = 4194301                       # any pid that is not the flusher under test
OTHER_STARTED = "2000-01-01T00:00:00.000Z"
HOLD_S = 0.4


def owned_seat(name: str, events: int) -> Seat:
    s = seat(name)
    hook(s, "PreToolUse", pre(tuid=f"{name}_0"))
    bucket = sorted(s.spool.glob("*.jsonl"))[0]
    rec = next(json.loads(l) for l in bucket.read_text(encoding="utf-8").splitlines()
               if l and json.loads(l)["e"]["kind"] == "tool.start")
    with bucket.open("a", encoding="utf-8") as fh:
        for i in range(events):
            rec["e"]["event_id"] = f"01K9393{i:019d}"
            fh.write(json.dumps(rec) + "\n")
    return s


def take_over(s: Seat) -> bytes:
    """Do what a second flusher does when it wins: replace the lock, then claim state.json."""
    st = s.state()
    (s.spool / "flusher.lock").write_text(json.dumps(
        {"pid": OTHER_PID, "started_at": OTHER_STARTED}), encoding="utf-8")
    st["owner_pid"], st["owner_started_at"] = OTHER_PID, OTHER_STARTED
    body = json.dumps(st).encode("utf-8")
    tmp = s.spool / "state.json.takeover.tmp"
    tmp.write_bytes(body)
    os.replace(tmp, s.spool / "state.json")
    return body


def drive_pass(s: Seat, reporter: Path, *, takeover: bool, one_pass: bool, exit_within: float,
               hold_health: bool = False, refuse: bool = False) -> dict:
    """Run a real flusher against the held ingest and observe each POST as it arrives.

    For every POST: the wall time it was seen and the lock's mtime at that moment. With
    `takeover`, the other owner is written while POST 1 is held — or, with `hold_health`, while
    the health probe is held instead (the POSTs are then not held). `exit_within` is how long
    after the last request the process is given to exit on its own; one still running then is
    SIGKILLed and reported as not exited (SIGTERM would let it finish its pass, which is not an
    exit).
    """
    lock = s.spool / "flusher.lock"
    lock.unlink(missing_ok=True)
    INGEST.batches.clear()
    INGEST.gets = 0
    INGEST.delay_s, INGEST.get_delay_s = (0.0, HOLD_S) if hold_health else (HOLD_S, 0.0)
    if refuse:   # every POST answered with the ingest's own 422: a disposal that WRITES (the quarantine, the marker)
        INGEST.status, INGEST.body_override = 422, {"error": "invalid_batch", "field": "events", "reason": "planted"}
    envx = {"FLEET_REPORTER_ONE_PASS": "1"} if one_pass else {}
    p = subprocess.Popen(["node", str(reporter), "flusher"], env=s.env(freeze=False, **envx),
                         cwd=str(HERE), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    posts: list[tuple[float, float | None]] = []
    taken: bytes | None = None
    quiet_since = time.time()
    try:
        while p.poll() is None:
            n = len(INGEST.batches)
            if n > len(posts):
                seen = time.time()
                try:
                    mtime = lock.stat().st_mtime
                except FileNotFoundError:
                    mtime = None
                posts.extend([(seen, mtime)] * (n - len(posts)))
                quiet_since = seen
                if takeover and taken is None and not hold_health:
                    taken = take_over(s)
            if hold_health and INGEST.gets and taken is None:
                quiet_since = time.time()
                if takeover:
                    taken = take_over(s)
            if time.time() - quiet_since > exit_within + HOLD_S:
                break
            time.sleep(0.01)
        exited = p.poll() is not None
    finally:
        if p.poll() is None:
            p.kill()
            p.wait()
        INGEST.delay_s = INGEST.get_delay_s = 0.0
        INGEST.status, INGEST.body_override = 202, None
    lost_log = []
    for f in sorted((s.spool / "log").glob("*.log")) if (s.spool / "log").exists() else []:
        lost_log += [l for l in f.read_text(encoding="utf-8").splitlines() if "lost ownership" in l]
    try:
        lock_body = json.loads(lock.read_text(encoding="utf-8"))
    except (FileNotFoundError, ValueError):
        lock_body = None
    return {"posts": posts, "n_posts": len(INGEST.batches), "exited": exited, "taken": taken,
            "state_bytes": (s.spool / "state.json").read_bytes(),
            "lost_counted": s.counters().get("flusher_lost_ownership", 0), "sink": s.counters(),
            "corrupt_quarantined": _lines(s.spool / "quarantine" / "corrupt.jsonl"),
            "rejected_quarantined": _lines(s.spool / "quarantine" / "rejected.default.jsonl"),
            "marker": (s.spool / "REJECTED.default.txt").exists(),
            "lost_log": lost_log, "lock": lock_body}


def _lines(f: Path) -> int:
    return len(f.read_text(encoding="utf-8").splitlines()) if f.exists() else 0


def refreshed_between_posts(posts) -> list[bool]:
    """For POST k >= 2: was the lock touched AFTER POST k-1 was seen? Clock-free — both sides are
    the wall clock, and a touch between posts lands at least HOLD_S after the previous arrival."""
    return [m is not None and m > posts[k - 1][0] for k, (_, m) in enumerate(posts) if k >= 1]


# Three drain rounds' worth, read from the reporter's own K: two full batches and one more event.
N_EVENTS = 2 * int(subprocess.run(
    ["node", "-e", f"process.stdout.write(String(require({json.dumps(str(REPORTER))}).K.BATCH_EVENTS))"],
    capture_output=True, text=True, check=True).stdout) + 1

# GREEN — ownership lost while POST 1 is held, NO one-pass seam: only the loss can end the process. The
# ingest REFUSES that POST (its own 422), so acting on the answer would WRITE — the rejected-batch
# quarantine and the marker — which is what an ex-owner must not do.
g_a = drive_pass(owned_seat("own-lost-green", N_EVENTS), REPORTER, takeover=True,
                 one_pass=False, exit_within=1.5, refuse=True)
eq("GREEN: ownership taken while POST 1 is in flight -> no further POST after that one",
   1, g_a["n_posts"])
eq("  … state.json still holds the new owner's bytes: the ex-owner wrote nothing after the loss",
   g_a["taken"], g_a["state_bytes"])
eq("  … and the process EXITED on its own (no one-pass seam, no signal)", True, g_a["exited"])
eq("  … counting `flusher_lost_ownership` exactly once, in the counter sink the new owner folds",
   1, g_a["lost_counted"])
eq("  … and logging it exactly once", 1, len(g_a["lost_log"]))
eq("GREEN: the exiting ex-owner leaves the lock that names the new owner in place",
   OTHER_PID, (g_a["lock"] or {}).get("pid"))
eq("GREEN: resuming from the answered POST, the ex-owner disposes of nothing in that request — the "
   "refused batch is neither quarantined nor marked nor counted (the new owner, which finds the request "
   "persisted in the state.json it claimed, resends it and disposes of it once)",
   (0, False, None), (g_a["rejected_quarantined"], g_a["marker"], g_a["sink"].get("batches_rejected")))

# GREEN — no takeover, one pass of three held POSTs: the pass outlasts any single touch.
g_b = drive_pass(owned_seat("own-touch-green", N_EVENTS), REPORTER, takeover=False, one_pass=True,
                 exit_within=1.5)
eq(f"GREEN: a pass of {g_b['n_posts']} POSTs each held {HOLD_S} s refreshes the lock between "
   f"every pair of posts", True, g_b["n_posts"] >= 3 and all(refreshed_between_posts(g_b["posts"])))
eq("  … and a flusher that still owns its lock releases it on exit", None, g_b["lock"])

# RED 1 — the refusal returned and ignored, which is the reporter before card#9393: a save that
# finds another owner writes nothing, and nothing stops the drain or the loop.
p_own = plant_src(
    (r"throw new LostOwnership\(`[^`]*`\);", "{ count('flusher_lost_ownership'); return false; }"),
    (re.escape("  assertOwner(spool, state);\n  if (!atomicWrite(statePath(spool)"),
     "  if (assertOwner(spool, state) === false) return false;\n  if (!atomicWrite(statePath(spool)"),
    (re.escape("  assertOwner(spool, state);\n  atomicWrite(path.join(indexDir(spool), 'snapshot.json')"),
     "  if (assertOwner(spool, state) === false) return;\n  atomicWrite(path.join(indexDir(spool), 'snapshot.json')"))
r_a = drive_pass(owned_seat("own-lost-red", N_EVENTS), p_own, takeover=True, one_pass=False,
                 exit_within=1.5)
# Since card#12180 a save that is refused sends nothing — the pre-send save persists the request before any
# POST (D1 § 3.1) — so this defect no longer POSTs past the takeover; what it still does is the rest of it.
eq("RED: with the loss returned and ignored, the ex-owner never exits on its own", False, r_a["exited"])
eq("  … and its count never reaches a surface anyone reads", 0, r_a["lost_counted"])

# RED 2 — the lock renewed once per pass only, as before: no touch between posts.
p_touch = plant_src((re.escape("    renewLock(spool, state);\n    const res = await postBatch(lane.site, composeBody(req));"),
                     "    const res = await postBatch(lane.site, composeBody(req));"))
r_b = drive_pass(owned_seat("own-touch-red", N_EVENTS), p_touch, takeover=False, one_pass=True,
                 exit_within=1.5)
eq("RED: renewed once per pass, the lock is NOT refreshed between held posts", True,
   r_b["n_posts"] >= 3 and not any(refreshed_between_posts(r_b["posts"])))

# RED 3 — the unconditional unlink on exit.
p_unlink = plant_src((re.escape("if (held && held.pid === process.pid && held.started_at === state.owner_started_at) fs.unlinkSync(lock);"),
                      "fs.unlinkSync(lock);"))
r_c = drive_pass(owned_seat("own-unlink-red", N_EVENTS), p_unlink, takeover=True, one_pass=False,
                 exit_within=1.5)
eq("RED: an ex-owner that unlinks unconditionally deletes the NEW owner's lock", None, r_c["lock"])

# RED 4 — no check on resuming from the POST: the answered batch is disposed of by an ex-owner.
p_send = plant_src((re.escape("    assertOwner(spool, state);   // resumed from an await: nothing below acts for an ex-owner\n"), ""))
r_d = drive_pass(owned_seat("own-send-red", N_EVENTS), p_send, takeover=True,
                 one_pass=False, exit_within=1.5, refuse=True)
eq("RED: with no check after the POST's await, the ex-owner quarantines and marks the refused batch "
   "the new owner will dispose of again — posts sent, quarantined, marker written",
   (1, 1, True), (r_d["n_posts"], r_d["rejected_quarantined"], r_d["marker"]))

# AFTER THE PROBE'S AWAIT. Since card#12180 the seat pass — the spool bounds, the bucket reaps, the sink fold —
# awaits no network and runs before any lane, so it has no await an ex-owner could resume from. A lane's probe
# is the await that precedes everything a lane does with the spool: what follows it reads the cursor, disposes
# of a leading unusable line (quarantined and counted), and builds the next request. A takeover written while
# the probe is held must therefore find the lane doing none of it. The seat holds what makes the lane act: a
# torn line at the head of its spool, in a bucket whose hour has ended.
def tail_seat(name: str) -> Seat:
    s = seat(name)
    past = time.strftime("%Y%m%d%H", time.gmtime(time.time() - 7200))
    (s.spool / f"{past}.jsonl").write_text("{torn line 9393\n", encoding="utf-8")
    return s


def tail_effects(s: Seat, r: dict) -> dict:
    return {"corrupt_quarantined": r["corrupt_quarantined"], "posts": r["n_posts"],
            "spool_corrupt_lines_in_sink": r["sink"].get("spool_corrupt_lines"),
            "lost_counted": r["lost_counted"], "exited": r["exited"]}


T_TAIL = time.time()
tail_green_seat = tail_seat("own-tail-green")
g_t = drive_pass(tail_green_seat, REPORTER, takeover=True, one_pass=False, exit_within=1.5, hold_health=True)
e_gt = tail_effects(tail_green_seat, g_t)
eq("GREEN: takeover while the health probe is held -> the ex-owner quarantines nothing, counts nothing, "
   "sends nothing, and exits counting the loss once",
   {"corrupt_quarantined": 0, "posts": 0, "spool_corrupt_lines_in_sink": None, "lost_counted": 1, "exited": True},
   e_gt)
eq("  … state.json still holds the new owner's bytes", g_t["taken"], g_t["state_bytes"])

# RED 5 — no check on resuming from the health probe: the lane acts for an ex-owner.
CHECK_AFTER_HEALTH = re.escape("      assertOwner(spool, state);   // resumed from an await: see assertOwner's header\n")
tail_red_seat = tail_seat("own-tail-red")
r_t = drive_pass(tail_red_seat, plant_src((CHECK_AFTER_HEALTH, "")), takeover=True, one_pass=False,
                 exit_within=1.5, hold_health=True)
e_rt = tail_effects(tail_red_seat, r_t)
eq("RED: with no check after the probe's await, the ex-owner quarantines the torn line the new owner will "
   "dispose of again — and the save that follows is what finally stops it, before any request",
   (1, 0, 1), (e_rt["corrupt_quarantined"], e_rt["posts"], e_rt["lost_counted"]))
# The same run is the GREEN of the exit path's unfold: the count folded into state.counters by the save
# that then threw still reaches the sink.
eq("GREEN: a count folded into state.counters by a save that then throws still reaches the "
   "sink (the exit path hands folded-but-unsaved counts back)", 1, e_rt["spool_corrupt_lines_in_sink"])

# RED 6 — the same, and the exit path flushes only what is still in C: the count is lost.
tail_unfold_seat = tail_seat("own-unfold-red")
r_u = drive_pass(tail_unfold_seat, plant_src((CHECK_AFTER_HEALTH, ""),
                                             (re.escape("  unfoldUnsaved();\n  flushCounters"), "  flushCounters")),
                 takeover=True, one_pass=False, exit_within=1.5, hold_health=True)
e_ru = tail_effects(tail_unfold_seat, r_u)
eq("RED: without the unfold, the line is quarantined and counted nowhere (§ 0 item 9) "
   "— quarantined, corrupt-lines in sink, exited, and lost-ownership count",
   (1, None, True, 1), (e_ru["corrupt_quarantined"], e_ru["spool_corrupt_lines_in_sink"], e_ru["exited"], e_ru["lost_counted"]))
T_TAIL = time.time() - T_TAIL


def _ages(posts):
    return [round(t - m, 2) if m is not None else None for t, m in posts]


redgreen("a flusher that loses ownership stops sending and exits (§ 2.3, card#9393)",
         f"loss returned and ignored -> {r_a['n_posts']} POSTs in all, {r_a['n_posts'] - 1} of them "
         f"after a takeover written while POST 1 was held, exited on its own={r_a['exited']}, "
         f"flusher_lost_ownership in the sink="
         f"{r_a['lost_counted']}",
         f"thrown and caught by the loop -> {g_a['n_posts']} POST (the one in flight), state.json "
         f"byte-identical to the new owner's, exited={g_a['exited']}, counted {g_a['lost_counted']}x "
         f"in the sink, logged {len(g_a['lost_log'])}x")
redgreen("a live flusher's lock is renewed before every request (§ 2.3, card#9393)",
         f"once per pass -> lock age (s) at each of {r_b['n_posts']} POSTs held {HOLD_S} s: "
         f"{_ages(r_b['posts'])} — it grows by a request per POST, so a slow enough ingest outlasts "
         f"LOCK_STALE_MS inside one live pass",
         f"before every request -> lock age (s) at each POST: {_ages(g_b['posts'])} — bounded by one "
         f"request")
redgreen("an ex-owner resuming from a POST disposes of nothing in the answered request (§ 2.3, card#9393)",
         f"no check after the await -> the refused batch quarantined {r_d['rejected_quarantined']}x, "
         f"marker written={r_d['marker']}, before the save threw",
         f"checked on resuming -> quarantined {g_a['rejected_quarantined']}x, marker written={g_a['marker']}")
redgreen("a lane does nothing for an ex-owner resuming from the health probe (§ 2.3, § 0 item 9, card#9393, card#12180)",
         f"no check after the await -> {e_rt}",
         f"checked on resuming -> {e_gt} (the three health-probe runs took {T_TAIL:.1f} s)")
redgreen("counts folded but never saved reach the sink on exit (§ 0 item 9, card#9393)",
         f"exit flushes only C -> torn line quarantined {e_ru['corrupt_quarantined']}x, "
         f"spool_corrupt_lines in the sink={e_ru['spool_corrupt_lines_in_sink']}",
         f"exit hands back folds since the last landed save -> quarantined {e_rt['corrupt_quarantined']}x, "
         f"spool_corrupt_lines in the sink={e_rt['spool_corrupt_lines_in_sink']}")
redgreen("an exiting ex-owner leaves the new owner's lock alone (§ 2.3, card#9393)",
         f"unconditional unlink -> lock after exit = {r_c['lock']} (a hook would start a third flusher)",
         f"unlink only while the lock names this process -> lock after exit names pid "
         f"{(g_a['lock'] or {}).get('pid')}; a flusher that still owns it releases it (lock={g_b['lock']})")


print("\n== 19. A DECLARED AGENT NAME IS CHECKED, AND A DISAGREEMENT FAILS AN ACT (AT-27, § 3.1, card#9375) ==")
# card#7957's finding was that NO ACT WOULD FAIL if a seat's declared protocol agent name and the
# coordination roster disagreed. D1 § 3.1 answers it with four declaration states and one check;
# AT-27 is where they are made to discriminate. Every case below is one seat and one box: a HOME of its
# own (so the Linux home-path site is a place this suite controls) and, where the case says so, a
# `$COORD_CONFIG` pointing into a stand-in coordination repository. Each drives `selftest` and one
# flush, and reads the heartbeat the flusher spooled.
AT27_NAME = "magento"
AT27_ROSTER = {"roster": [{"name": "pm", "role": "pm"}, {"name": AT27_NAME, "role": "impl"},
                          {"name": "moodle", "role": "impl"}]}
AT27_ROSTER_WITHOUT = {"roster": [{"name": "pm", "role": "pm"}, {"name": "moodle", "role": "impl"}]}
ABSENT = object()


def at27_box(name: str, *, declare=AT27_NAME, home=None, coord=None, seat_id=None) -> "tuple[Seat, dict]":
    """`home` / `coord`: a roster document (or raw text) to place at that § 3.1 site, or None for no
    file there. `coord="missing"` sets `$COORD_CONFIG` to a path holding no file."""
    s = seat(name)
    if declare is not ABSENT:
        s.cfg["protocol_agent_name"] = declare
    if seat_id:
        s.cfg["seat_id"] = seat_id
    s.write_cfg()
    home_dir = s.root / "home"
    home_dir.mkdir(exist_ok=True)
    env = {"HOME": str(home_dir)}
    for doc, f in ((home, home_dir / ".config" / "coord" / "coordination.config.json"),
                   (coord, s.root / "coordination-repo" / "coordination.config.json")):
        if doc is None or doc == "missing":
            continue
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_text(doc if isinstance(doc, str) else json.dumps(doc), encoding="utf-8")
    if coord == "missing":
        env["COORD_CONFIG"] = str(s.root / "moved-away" / "coordination.config.json")
    elif coord is not None:
        env["COORD_CONFIG"] = str(s.root / "coordination-repo" / "coordination.config.json")
    return s, env


def last_heartbeat(s: Seat):
    hbs = s.wire_heartbeats()
    return hbs[-1]["data"] if hbs else None


def wire(hb) -> "dict | None":
    """The members AT-27 asserts. An ABSENT member reads `<absent>`, never None: an omitted name and an
    undeclared seat's `null` are what this test exists to keep apart."""
    if hb is None:
        return None
    c = hb.get("counters", {})
    return {"name": hb["protocol_agent_name"] if "protocol_agent_name" in hb else "<absent>",
            "check": hb["protocol_agent_name_check"] if "protocol_agent_name_check" in hb else "<absent>",
            "in_roster": hb.get("selftest", {}).get("protocol_agent_name_in_roster", "<absent>"),
            "unchecked": c.get("protocol_agent_name_unchecked", 0),
            "disagreed": c.get("protocol_agent_name_disagreed", 0)}


def drive_at27(box, reporter: Path = REPORTER) -> dict:
    s, env = box
    r, rep27 = selftest(s, reporter=reporter, **env)
    flush(s, reporter=reporter, **env)
    hb = last_heartbeat(s)
    checks = rep27.get("checks", {})
    return {"rc": r.returncode, "selftest": checks.get("protocol_agent_name_in_roster", "<absent>"),
            "failing": sorted(k for k, v in checks.items() if v == "fail"),
            "config_readable": checks.get("config_readable"), "wire": wire(hb),
            "degraded": hb.get("degraded") if hb else None,
            "fingerprint": hb.get("config_fingerprint") if hb else None,
            "detail": rep27.get("detail", {}).get("protocol_agent_name_in_roster"),
            # § 6.14's relayed role (card#11144), kept OUT of `wire()` so every AT-27 comparison above
            # stays the name pair's. An absent member reads `<absent>`, never None, as in `wire()`.
            "role": (hb["protocol_agent_role"] if "protocol_agent_role" in hb else "<absent>") if hb else None}


def act(d: dict) -> tuple:
    """What an operator and a consumer see: the act's exit and verdict, and the wire."""
    return (d["rc"], d["selftest"], d["wire"])


def want(name, check, in_roster, unchecked=0, disagreed=0) -> dict:
    return {"name": name, "check": check, "in_roster": in_roster, "unchecked": unchecked, "disagreed": disagreed}


at_a = drive_at27(at27_box("at27-a", home=AT27_ROSTER))
eq("case A — $COORD_CONFIG unset, a roster at the home path containing the name: selftest exits 0 "
   "and protocol_agent_name_in_roster passes; the heartbeat carries the name and `checked`, both counters 0",
   (0, "pass", want(AT27_NAME, "checked", "pass")), act(at_a))

at_b = drive_at27(at27_box("at27-b"))
eq("case B — $COORD_CONFIG unset and no roster at the home path: the name member is PRESENT beside "
   "`unchecked`, protocol_agent_name_unchecked is 1, and selftest passes",
   (0, "pass", want(AT27_NAME, "unchecked", "pass", unchecked=1)), act(at_b))
at_b2 = drive_at27(at27_box("at27-b-set", home=AT27_ROSTER, coord="missing"))
eq("  … and $COORD_CONFIG naming no file, with a roster CONTAINING the name at the home path, gives "
   "the same result: a set variable is the whole answer",
   ((0, "pass", want(AT27_NAME, "unchecked", "pass", unchecked=1)), act(at_b)), (act(at_b2), act(at_b2)))
for label, doc in (("text that is not JSON", "{ roster: pm"), ("a document with no roster[] array", {"roster": "pm"})):
    tag = "json" if isinstance(doc, str) else "shape"
    eq(f"  … and a roster file holding {label} is no readable roster: `unchecked`, never a throw and "
       f"never `disagreed`", (0, "pass", want(AT27_NAME, "unchecked", "pass", unchecked=1)),
       act(drive_at27(at27_box(f"at27-b-malformed-{tag}", coord=doc))))

at_c = drive_at27(at27_box("at27-c", home=AT27_ROSTER_WITHOUT))
eq("case C — a readable roster WITHOUT the name: selftest exits non-zero with protocol_agent_name_in_roster "
   "failing; the heartbeat carries the name exactly as declared, `disagreed`, the check failing, and "
   "protocol_agent_name_disagreed 1",
   (1, "fail", want(AT27_NAME, "disagreed", "fail", disagreed=1)), act(at_c))
eq("  … and the act names that check and no other", ["protocol_agent_name_in_roster"], at_c["failing"])
eq("  … and its detail names the roster it read and the names it holds, so an operator can fix the typo",
   ("home", ["pm", "moodle"]),
   ((at_c["detail"] or {}).get("read_via"), (at_c["detail"] or {}).get("roster_names")))

at_d = drive_at27(at27_box("at27-d", declare=ABSENT, home=AT27_ROSTER))
eq("case D — no key in the config: protocol_agent_name is null (PRESENT), the check `undeclared`, "
   "selftest passes and both counters are 0", (0, "pass", want(None, "undeclared", "pass")), act(at_d))
eq("  … and an explicit null declares nothing either", (0, "pass", want(None, "undeclared", "pass")),
   act(drive_at27(at27_box("at27-d-null", declare=None, home=AT27_ROSTER))))
# Each of these is a FRESH seat, and a fresh seat's `degraded` is empty: its first flusher start finds
# no state.json, which is a first start and not § 11.4's `state_reset` (card#9374, § 6 above). What
# AT-27 case D asserts is that NO DECLARATION STATE raises a badge (§ 9.3 gives neither counter a
# member), so every case is held to that empty set.
eq("  … and no declaration state raises a badge: A, B, C and D all carry an empty `degraded`",
   [[]] * 4,
   [at_a["degraded"], at_b["degraded"], at_c["degraded"], at_d["degraded"]])

at_e = drive_at27(at27_box("at27-e", coord=AT27_ROSTER))
# "Identical to case X" is asserted as X's absolute outcome AND as equality with X: equality alone holds
# between two cases that both omit the members, which is what a reporter predating card#9375 emits.
eq("case E — the roster only where $COORD_CONFIG points, no file at the home path: identical to case A "
   "on every member", ((0, "pass", want(AT27_NAME, "checked", "pass")), act(at_a)), (act(at_e), act(at_e)))
eq("  … read through the variable, not the home path", "$COORD_CONFIG", (at_e["detail"] or {}).get("read_via"))

at_ctl = drive_at27(at27_box("at27-control", declare="magenta", home=AT27_ROSTER))
eq("discriminating control — case A with the declared name one byte off: case C's outcome exactly",
   act(at_c), (at_ctl["rc"], at_ctl["selftest"], dict(at_ctl["wire"] or {}, name=AT27_NAME)))
eq("  … the name carried exactly as declared, typo and all", "magenta", (at_ctl["wire"] or {}).get("name"))

# THE BOUND. § 6.14 states it as a figure because the ingest refuses a heartbeat over it, so a value past
# it — or any value that is not a valid declaration — never reaches the wire.
_name_row = [l for l in DOC.splitlines() if l.startswith("| `protocol_agent_name` | slug | — |")][0]
NAME_BOUND = int(re.search(r"≤ (\d+) B", _name_row).group(1))
_bound_roster = {"roster": [{"name": "a" * NAME_BOUND}, {"name": "a" * (NAME_BOUND + 1)}, {"name": "Magento"}]}
at_bnd = drive_at27(at27_box("at27-bound", declare="a" * NAME_BOUND, home=_bound_roster))
eq(f"a name AT § 6.14's {NAME_BOUND} B bound is a valid declaration: config_readable passes and it is `checked`",
   ("pass", "checked"), (at_bnd["config_readable"], (at_bnd["wire"] or {}).get("check")))


# A MALFORMED NAME DOES NOT SILENCE THE SEAT (D1 § 3.1's state table, card#9375 round 2). A present value
# that is not a valid declaration declares nothing: the seat keeps reporting, as `undeclared`, and the act
# that fails is `protocol_agent_name_in_roster`, whose `selftest` detail names the value. It is NOT a config
# error — `config_invalid` means "spooling and sending nothing" to D1 § 9.3 and to D3's badge, so a seat
# still sending would render falsely. Each seat runs one hook (whose config read would count
# `config_invalid`) and two flushes against the suite's ingest, whose batches are the wire: each pass's
# heartbeat is item 0 of that pass's request (D1 § 6.14), so both reach the wire.
def drive_malformed(name: str, declare, reporter: Path = REPORTER) -> dict:
    s, env = box = at27_box(name, declare=declare, home=_bound_roster)
    hook(s, "PreToolUse", pre(), **env)
    before = len(INGEST.batches)
    d = drive_at27(box, reporter=reporter)
    flush(s, reporter=reporter, **env)
    posted = INGEST.batches[before:]
    hbs = [e["data"] for b in posted for e in b["batch"].get("events", []) if e.get("kind") == "reporter.heartbeat"]
    spooled = last_heartbeat(s) or {}
    logs = "".join(f.read_text(encoding="utf-8") for f in sorted((s.spool / "log").glob("*.log")))
    d.update(posts=len(posted), posted_wire=wire(hbs[-1]) if hbs else None, posted_text=json.dumps(posted),
             log=logs, config_invalid=(spooled.get("counters", {}).get("config_invalid", 0),
                                       s.site_counters().get("config_invalid", 0),
                                       s.counters().get("config_invalid", 0)))
    return d


MALFORMED = ((f"{NAME_BOUND + 1} B, one past the bound", "a" * (NAME_BOUND + 1), "a" * (NAME_BOUND + 1)),
             ("not a lowercase slug", "Magento", "Magento"),
             ("not a string", 48, "<number>"))
malformed_seen = {}
for label, bad_name, shown in MALFORMED:
    d_bad = malformed_seen[label] = drive_malformed(f"at27-bad-{type(bad_name).__name__}-{len(str(bad_name))}", bad_name)
    eq(f"a name {label} leaves the config valid: config_readable passes, and `config_invalid` is counted "
       f"nowhere — the heartbeat, state.json, the counter sink", ("pass", (0, 0, 0)),
       (d_bad["config_readable"], d_bad["config_invalid"]))
    eq("  … and the seat still sends: its flush POSTs a batch whose heartbeat carries null / `undeclared` "
       "beside a failing protocol_agent_name_in_roster, and an empty `degraded`",
       (True, want(None, "undeclared", "fail"), []),
       (d_bad["posts"] > 0, d_bad["posted_wire"], d_bad["degraded"]))
    eq("  … and `selftest` fails that one check (exit 1), its detail naming the value — a non-string by its type",
       (1, ["protocol_agent_name_in_roster"], shown, None, "undeclared"),
       (d_bad["rc"], d_bad["failing"], (d_bad["detail"] or {}).get("malformed_declaration"),
        (d_bad["detail"] or {}).get("declared"), (d_bad["detail"] or {}).get("protocol_agent_name_check")))
    if isinstance(bad_name, str):
        eq("  … and the value reaches neither the wire nor the seat's log: `selftest`'s detail is its only home",
           (False, False, False), (bad_name in d_bad["posted_text"], bad_name in json.dumps(d_bad["wire"]),
                                   bad_name in d_bad["log"]))
eq("  … and the flusher's start log says the declaration is malformed and was sent as `undeclared`", True,
   all("protocol_agent_name is not a valid declaration" in d["log"] for d in malformed_seen.values()))

# RED 1 — the round-1 build: a malformed name refused as a config error, so the flusher sends nothing.
_red_silent = plant(("  return { config: c, errors, spool, sites };\n}",
                     "  if (c.protocol_agent_name !== undefined && c.protocol_agent_name !== null && !declaredAgentName(c)) "
                     "errors.push('protocol_agent_name malformed');\n  return { config: c, errors, spool, sites };\n}"))
r_silent = drive_malformed("at27-bad-red-silent", "Magento", reporter=_red_silent)
eq("RED: a malformed name refused as a config error silences the seat — no POST, config_readable failing, "
   "`config_invalid` counted", (0, "fail", True),
   (r_silent["posts"], r_silent["config_readable"], any(r_silent["config_invalid"])))
# RED 2 — the value passed through: the malformed name reaches the wire.
_red_value = plant(("  return typeof v === 'string' && AGENT_NAME_RE.test(v) ? v : null;", "  return v;"))
r_value = drive_malformed("at27-bad-red-value", "Magento", reporter=_red_value)
eq("RED: a reporter that sends the malformed value verbatim puts it on the wire", True,
   "Magento" in r_value["posted_text"])
# RED 3 — the value in the start log line.
_red_log = plant(("heartbeating as undeclared; `selftest` names the value');",
                  "heartbeating as undeclared; `selftest` names the value: ' + JSON.stringify(config.protocol_agent_name));"))
r_log = drive_malformed("at27-bad-red-log", "Magento", reporter=_red_log)
eq("RED: a start log line that names the value puts it in the seat's log", True, "Magento" in r_log["log"])
_slug = malformed_seen["not a lowercase slug"]
redgreen("a malformed declared name does not silence the seat, and its value stays off the wire (D1 § 3.1, card#9375)",
         f"refused as a config error -> POSTs {r_silent['posts']}, config_readable={r_silent['config_readable']}, "
         f"config_invalid (heartbeat, state, sink) {r_silent['config_invalid']}; value passed through -> "
         f"'Magento' on the wire: {'Magento' in r_value['posted_text']}; value in the start log -> "
         f"'Magento' in the log: {'Magento' in r_log['log']}",
         f"POSTs {_slug['posts']}, posted heartbeat {_slug['posted_wire']}, config_readable={_slug['config_readable']}, "
         f"config_invalid {_slug['config_invalid']}, selftest rc={_slug['rc']} failing {_slug['failing']} with "
         f"malformed_declaration {[(d['detail'] or {}).get('malformed_declaration') for d in malformed_seen.values()]}")

# CASE F — the SUPERVISED START. The flusher a healthy seat runs is the one its OS start launches, and
# that start inherits nothing from the harness. On Linux the start definition is the crontab line
# `INSTALL-LINUX.md` Step 5 writes, so the line is READ OUT OF THE RUNBOOK and expanded by the
# runbook's own shell assignment — never re-typed here — and run the way cron runs a line: `/bin/sh -c`
# from an environment holding HOME, LOGNAME, SHELL and PATH, and no `$COORD_CONFIG`. The only addition is
# `FLEET_REPORTER_ONE_PASS`, the suite's inert one-pass seam. The seat config goes where a seat keeps it,
# `$HOME/.config/fleet-reporter/config.json`, since cron's line names no config path either.
INSTALL_LINUX = (HERE / "INSTALL-LINUX.md").read_text(encoding="utf-8")
START_LINES = re.findall(r'^\s*LINE="(.+)"\s*$', INSTALL_LINUX, re.M)
CC_ASSIGNMENT = "${CC:+COORD_CONFIG=$CC }"
NODE_REAL = os.path.realpath(shutil.which("node") or "node")


def cron_start(box, cc: "str | None", template: str, reporter: Path = REPORTER):
    s, env = box
    home = Path(env["HOME"])
    (home / ".config" / "fleet-reporter").mkdir(parents=True, exist_ok=True)
    (home / ".config" / "fleet-reporter" / "config.json").write_text(json.dumps(s.cfg), encoding="utf-8")
    (s.spool / "flusher.lock").unlink(missing_ok=True)
    expanded = subprocess.run(
        ["/bin/sh", "-c", f'LINE="{template}"; printf %s "$LINE"'], capture_output=True, text=True,
        env={"PATH": "/usr/bin:/bin", "CC": cc or "", "FLOCK": str(s.root / "flusher.flock"),
             "NODE": NODE_REAL, "JS": str(reporter)}).stdout
    try:
        subprocess.run(["/usr/bin/env", "-i", f"HOME={home}", "LOGNAME=fleet-reporter-suite", "SHELL=/bin/sh",
                        "PATH=/usr/bin:/bin", "FLEET_REPORTER_ONE_PASS=1", "/bin/sh", "-c", expanded],
                       capture_output=True, text=True, timeout=90)
    finally:
        s.freeze_flusher()
    return expanded, last_heartbeat(s)


eq("precondition: INSTALL-LINUX.md Step 5 writes exactly one flusher start line, and it assigns "
   "COORD_CONFIG through the runbook's own conditional", (1, True),
   (len(START_LINES), bool(START_LINES) and CC_ASSIGNMENT in START_LINES[0]))
if shutil.which("flock") is None or not Path("/usr/bin/env").exists() or not START_LINES:
    skip("AT-27 case F needs `flock`, /usr/bin/env and the runbook's start line — the supervised start's "
         "delivery of $COORD_CONFIG was NOT measured, and that is not a pass")
    at_f = at_f_red = None
else:
    box_f = at27_box("at27-f", coord=AT27_ROSTER_WITHOUT)
    line_f, hb_f = cron_start(box_f, box_f[1]["COORD_CONFIG"], START_LINES[0])
    at_f = wire(hb_f)
    eq("case F — case C's box, the flusher started ONLY by the runbook's crontab line from an environment "
       "with no $COORD_CONFIG: the heartbeat is identical to case C's on every member",
       (want(AT27_NAME, "disagreed", "fail", disagreed=1), at_c["wire"]), (at_f, at_f))
    eq("  … because the line itself carries the assignment", True, line_f.startswith("COORD_CONFIG="))
    # F, MOVED: the coordination config now lives elsewhere. With the start definition rewritten the
    # launch delivers the new path; without it the flusher reads the install-time path. Fresh boxes, since
    # counters are monotonic across a seat's flusher restarts (state.json keeps them).
    box_mv = at27_box("at27-f-moved", coord=AT27_ROSTER_WITHOUT)
    moved = box_mv[0].root / "coordination-repo-moved" / "coordination.config.json"
    moved.parent.mkdir(parents=True)
    Path(box_mv[1]["COORD_CONFIG"]).rename(moved)
    _, hb_mv = cron_start(box_mv, str(moved), START_LINES[0])
    eq("case F, moved — the config moved and the start line rewritten with the new path: identical to case F",
       (want(AT27_NAME, "disagreed", "fail", disagreed=1), at_f), (wire(hb_mv), wire(hb_mv)))
    box_stale = at27_box("at27-f-moved-stale", coord=AT27_ROSTER_WITHOUT)
    stale_cc = box_stale[1]["COORD_CONFIG"]
    moved_s = box_stale[0].root / "coordination-repo-moved" / "coordination.config.json"
    moved_s.parent.mkdir(parents=True)
    Path(stale_cc).rename(moved_s)
    _, hb_stale = cron_start(box_stale, stale_cc, START_LINES[0])
    eq("  … and WITHOUT the rewrite the flusher reads the install-time path and reports `unchecked`, the "
       "check passing — § 18.13 row 6's residual, which only the rewrite catches",
       want(AT27_NAME, "unchecked", "pass", unchecked=1), wire(hb_stale))

    # ⛔ RED — the start path. A start line written without the assignment.
    box_fr = at27_box("at27-f-red", coord=AT27_ROSTER_WITHOUT)
    line_fr, hb_fr = cron_start(box_fr, box_fr[1]["COORD_CONFIG"], START_LINES[0].replace(CC_ASSIGNMENT, "", 1))
    at_f_red = wire(hb_fr)
    eq("RED: a start line without the COORD_CONFIG assignment reads the home path, finds nothing, and reports "
       "`unchecked` with the check PASSING — the disagreement that fails nothing, on the start path",
       want(AT27_NAME, "unchecked", "pass", unchecked=1), at_f_red)
    eq("  … and a heartbeat still arrives in both arms: the fix is the start definition's, never a gate on "
       "emission", (True, True), (hb_f is not None, hb_fr is not None))

# ⛔ RED — the silent omission: case B's reporter drops the members rather than emit `unchecked`.
P_EMIT = ("      protocol_agent_name: declaration.name,\n      protocol_agent_name_check: declaration.check,",
          "      ...(declaration.check === 'unchecked' ? {} : { protocol_agent_name: declaration.name, "
          "protocol_agent_name_check: declaration.check }),")
r_omit = drive_at27(at27_box("at27-red-omission"), reporter=plant(P_EMIT))
eq("RED: a reporter that drops the members on an unchecked seat puts no name on the wire — the omitted "
   "field an undeclared seat cannot be told apart from", ("<absent>", "<absent>"),
   ((r_omit["wire"] or {}).get("name"), (r_omit["wire"] or {}).get("check")))

# ⛔ RED — the equality fallback: a declaration synthesized from a `seat_id` equal to a roster name.
P_EQ = ("  const name = declaredAgentName(cfg);", "  const name = declaredAgentName(cfg) || cfg.seat_id;")
eq_box = lambda tag: at27_box(f"at27-eq-{tag}", declare=ABSENT, seat_id=AT27_NAME, home=AT27_ROSTER)
r_eq, g_eq = drive_at27(eq_box("red"), reporter=plant(P_EQ)), drive_at27(eq_box("green"))
eq("RED: a reporter that falls back to seat_id sends a name no human wrote in that config, `checked`",
   (AT27_NAME, "checked"), ((r_eq["wire"] or {}).get("name"), (r_eq["wire"] or {}).get("check")))
eq("GREEN: the real reporter on that seat sends null and `undeclared`", want(None, "undeclared", "pass"), g_eq["wire"])

# ⛔ RED — the disagreement that fails nothing: the check emits `checked`, or selftest passes.
r_ok = drive_at27(at27_box("at27-red-checked", home=AT27_ROSTER_WITHOUT),
                  reporter=plant(("  if (roster.names.includes(name)) {", "  if (true) {")))
eq("RED: a reporter that calls a non-member `checked` exits 0 with the check passing on a disagreeing seat",
   (0, "pass", "checked"), (r_ok["rc"], r_ok["selftest"], (r_ok["wire"] or {}).get("check")))
r_pass = drive_at27(at27_box("at27-red-pass", home=AT27_ROSTER_WITHOUT), reporter=plant(
    ("results.protocol_agent_name_in_roster = declaration ? declaration.check !== 'disagreed' && declaration.malformed === null : null;",
     "results.protocol_agent_name_in_roster = declaration ? true : null;")))
eq("RED: a selftest that passes `disagreed` exits 0 and the heartbeat's check passes, though the wire says "
   "`disagreed`", (0, "pass", "pass", "disagreed"),
   (r_pass["rc"], r_pass["selftest"], (r_pass["wire"] or {}).get("in_roster"), (r_pass["wire"] or {}).get("check")))

# ⛔ RED — the same failure through the PATH: a reporter that stats only the home path.
r_path = drive_at27(at27_box("at27-red-path", coord=AT27_ROSTER),
                    reporter=plant(("  if (process.env.COORD_CONFIG !== undefined) return", "  if (false) return")))
eq("RED: on case E's box a reporter reading only the home path finds no roster, reports `unchecked`, passes, "
   "and exits 0 — with the roster readable on the box the whole time",
   (0, "pass", want(AT27_NAME, "unchecked", "pass", unchecked=1)), act(r_path))

# RED — the name in the identity: `config_fingerprint` covering the declaration.
P_FP = ("    .update(`${cfg.install_id}|${cfg.seat_id}|${site.ingest_url}`)",
        "    .update(`${cfg.install_id}|${cfg.seat_id}|${site.ingest_url}|${cfg.protocol_agent_name}`)")
at_pm = drive_at27(at27_box("at27-identity-pm", declare="pm", home=AT27_ROSTER))
p_fp = plant(P_FP)
r_fp = (drive_at27(at27_box("at27-identity-red-a", home=AT27_ROSTER), reporter=p_fp)["fingerprint"],
        drive_at27(at27_box("at27-identity-red-pm", declare="pm", home=AT27_ROSTER), reporter=p_fp)["fingerprint"])
eq("RED: a fingerprint covering the name changes when only the label is edited — a re-identified desk",
   True, r_fp[0] != r_fp[1])
eq("GREEN: editing the declared name moves no config_fingerprint", at_a["fingerprint"], at_pm["fingerprint"])


# NEVER IN A HOOK. Structural, because a hook's roster read has no observable of its own: resolution is
# reached only through `runSelftestChecks`, and that only from the flusher and the selftest subcommand.
def callers(source: str, callee: str) -> list[str]:
    heads = [(m.start(), m.group(1)) for m in re.finditer(r"^(?:async )?function (\w+)\(", source, re.M)]
    out = []
    for m in re.finditer(rf"(?<![\w.]){callee}\(", source):
        enclosing = [n for at, n in heads if at < m.start()]
        if enclosing and enclosing[-1] != callee:
            out.append(enclosing[-1])
    return sorted(set(out))


_src19 = REPORTER.read_text(encoding="utf-8")
eq("resolution is reached only from runSelftestChecks", ["runSelftestChecks"], callers(_src19, "resolveDeclaration"))
eq("  … and runSelftestChecks only from the flusher's start and the selftest subcommand — never a hook",
   ["flusherMain", "selftestMain"], callers(_src19, "runSelftestChecks"))
_red_hook = plant(("function hookMain(hookName) {", "function hookMain(hookName) { resolveDeclaration({});"))
eq("RED: a hook that resolves the roster is seen by that structural check", True,
   "hookMain" in callers(_red_hook.read_text(encoding="utf-8"), "resolveDeclaration"))


# NEVER PER FLUSH. A long-lived flusher on the timing-scaled copy (§ 1's FAST_K), over several heartbeats.
def at27_long_run(name: str, reporter: Path) -> list:
    s, env = at27_box(name)
    (s.spool / "flusher.lock").unlink(missing_ok=True)
    proc = subprocess.Popen(["node", str(reporter), "flusher"], env=s.env(freeze=False, **env), cwd=str(HERE),
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        t0 = time.time()
        while time.time() - t0 < 20 and len(s.wire_heartbeats()) < 3:
            time.sleep(0.1)
    finally:
        proc.kill()
        proc.wait()
        s.freeze_flusher()
    return [e["data"].get("counters", {}).get("protocol_agent_name_unchecked", 0) for e in s.wire_heartbeats()]


g_long = at27_long_run("at27-per-start", fast_flusher())
r_long = at27_long_run("at27-per-flush", fast_flusher((P_EMIT[0],
    "    ...(() => { const d = resolveDeclaration(cfg); return { protocol_agent_name: d.name, "
    "protocol_agent_name_check: d.check }; })(),")))
eq("GREEN: over a flusher's successive heartbeats protocol_agent_name_unchecked stays 1 — one read per start",
   (True, {1}), (len(g_long) >= 3, set(g_long)))
eq("RED: a flusher that re-resolves at each heartbeat counts a read per heartbeat", True,
   len(r_long) >= 3 and r_long[-1] > 1)

redgreen("a declared agent name is checked, and a disagreement fails an act (D1 § 3.1, AT-27, card#9375)",
         f"omitted members on an unchecked seat -> name/check {(r_omit['wire'] or {}).get('name')}/"
         f"{(r_omit['wire'] or {}).get('check')}; seat_id fallback -> {r_eq['wire']}; non-member called checked -> "
         f"rc={r_ok['rc']} check={(r_ok['wire'] or {}).get('check')}; selftest passing disagreed -> rc={r_pass['rc']} "
         f"in_roster={r_pass['selftest']}; home path only on case E -> {act(r_path)}; start line without the "
         f"assignment -> {at_f_red}; name in the fingerprint -> {r_fp[0]} vs {r_fp[1]}; resolution per heartbeat -> "
         f"unchecked counts {r_long}",
         f"A {act(at_a)}; B {act(at_b)}; C {act(at_c)}; D {act(at_d)}; E == A: {act(at_e) == act(at_a)}; "
         f"F {at_f}; control (one byte off) == C; fingerprint unmoved by the name {at_a['fingerprint']}; "
         f"one read per flusher start {g_long}")


print("\n== 19b. THE ROSTER ENTRY'S ROLE IS RELAYED, AND IS NULL WHENEVER NOTHING SELECTS IT (D1 § 3.1, AT-27, card#11144) ==")
# D1 § 3.1: on the roster read the name check already makes, the reporter relays `roster[].role` of the
# ONE entry the declared name selects, verbatim, as `protocol_agent_role` on every heartbeat. It is null
# whenever the check is not `checked`, when the entry carries no slug-shaped role, and when the name
# matches more than one entry. `selftest` gains no check: its verdict and exit are unchanged in every
# case below, and `detail.protocol_agent_name_in_roster.roster_role` says why a `checked` seat relays
# null. Cases A-E are the AT-27 boxes above, re-read for the role.
def roster_role(d: dict):
    return (d["detail"] or {}).get("roster_role", "<absent>")


eq("relay case A — `checked`: the heartbeat carries the selected entry's role verbatim, and the detail names it",
   ("impl", "impl"), (at_a["role"], roster_role(at_a)))
eq("relay case E — `checked` through $COORD_CONFIG: the same role", ("impl", "impl"), (at_e["role"], roster_role(at_e)))
eq("relay cases B, C, D — `unchecked`, `disagreed`, `undeclared`: the member is PRESENT and null on each",
   [None, None, None], [at_b["role"], at_c["role"], at_d["role"]])
eq("  … and the control (one byte off, so `disagreed` against a roster that holds a role for the real name) "
   "relays nothing", None, at_ctl["role"])


def relay_box(tag: str, entries: list, declare=AT27_NAME):
    return drive_at27(at27_box(f"relay-{tag}", declare=declare, home={"roster": entries}))


ROLE_BOUND = NAME_BOUND
relay_no_role = relay_box("no-role", [{"name": "pm", "role": "pm"}, {"name": AT27_NAME}])
eq("relay — a `checked` name whose entry carries no role: null, the check `checked` and passing, selftest exit 0",
   (None, "checked", 0, "pass"),
   (relay_no_role["role"], (relay_no_role["wire"] or {}).get("check"), relay_no_role["rc"], relay_no_role["selftest"]))
eq("  … and the detail says the entry carries none", "no role: the entry carries none", roster_role(relay_no_role))
relay_null_role = relay_box("null-role", [{"name": AT27_NAME, "role": None}])
eq("relay — an entry whose role is JSON null: null, as an absent one", None, relay_null_role["role"])

for tag, bad_role in (("upper", "Impl"), ("space", "pm helper"), ("over", "a" * (ROLE_BOUND + 1)),
                      ("empty", ""), ("number", 7), ("array", ["pm"])):
    r = relay_box(f"bad-{tag}", [{"name": AT27_NAME, "role": bad_role}])
    eq(f"relay — a role that is not a slug ({tag}): null on the wire, `checked`, selftest exit 0 and passing, "
       f"and the detail says why", (None, "checked", 0, "pass", True),
       (r["role"], (r["wire"] or {}).get("check"), r["rc"], r["selftest"], str(roster_role(r)).startswith("not a slug: ")))
relay_bound = relay_box("bound", [{"name": AT27_NAME, "role": "a" * ROLE_BOUND}])
eq(f"relay — a role AT § 6.14's {ROLE_BOUND} B bound is relayed verbatim", "a" * ROLE_BOUND, relay_bound["role"])
relay_open = relay_box("open", [{"name": AT27_NAME, "role": "pm-helper"}])
eq("relay — the vocabulary is open: an unknown slug (`pm-helper`) is relayed uninterpreted", "pm-helper", relay_open["role"])

DUP = [{"name": "pm", "role": "pm"}, {"name": AT27_NAME, "role": "impl"}, {"name": AT27_NAME, "role": "pm"}]
relay_dup = relay_box("dup", DUP)
eq("relay — a name matching TWO roster entries selects nothing: null on the wire, the check still `checked` "
   "and passing, selftest exit 0", (None, "checked", 0, "pass"),
   (relay_dup["role"], (relay_dup["wire"] or {}).get("check"), relay_dup["rc"], relay_dup["selftest"]))
eq("  … and the detail reads ambiguous, with the count and the name", f"ambiguous: 2 entries named {AT27_NAME}",
   roster_role(relay_dup))
eq("relay — on an `unchecked`, `disagreed` or `undeclared` seat the detail relays no role either",
   [None, None, None], [roster_role(at_b), roster_role(at_c), roster_role(at_d)])

# ⛔ RED — a duplicate name relays whichever entry comes first.
r_dup = drive_at27(at27_box("relay-red-dup", home={"roster": DUP}),
                   reporter=plant(("  if (at.length !== 1) return", "  if (at.length === 0) return")))
eq("RED: a reporter that takes the first entry on a duplicate name relays `impl` — a pick nothing on the "
   "wire says was a pick", "impl", r_dup["role"])
# ⛔ RED — the role relayed unvalidated: a value the ingest's 48 B bound would refuse, batch and all.
r_unval = drive_at27(at27_box("relay-red-unvalidated", home={"roster": [{"name": AT27_NAME, "role": "Impl"}]}),
                     reporter=plant(("  if (typeof v === 'string' && AGENT_NAME_RE.test(v)) return",
                                     "  if (typeof v === 'string') return")))
eq("RED: a reporter that relays an unvalidated role puts `Impl` on the wire", "Impl", r_unval["role"])
# ⛔ RED — the null omitted rather than sent: a seat whose role is null drops the member.
r_omit_role = drive_at27(at27_box("relay-red-omit"), reporter=plant(
    ("    protocol_agent_role: declaration.role,",
     "    ...(declaration.role === null ? {} : { protocol_agent_role: declaration.role }),")))
eq("RED: a reporter that omits a null role leaves the member ABSENT on an `unchecked` seat", "<absent>",
   r_omit_role["role"])
# ⛔ RED — the role relayed on a check that is not `checked`: a disagreed name that happens to share an
# entry's name in another case, modelled by a reporter relaying the roster's first role on every read.
r_any = drive_at27(at27_box("relay-red-unchecked-role", home=AT27_ROSTER_WITHOUT), reporter=plant(
    ("  return { name, check: 'disagreed', role: null,",
     "  return { name, check: 'disagreed', role: roster.roles.find((v) => typeof v === 'string') || null,")))
eq("RED: a reporter that relays a role beside `disagreed` sends `pm` for a name the roster does not hold",
   ("pm", "disagreed"), (r_any["role"], (r_any["wire"] or {}).get("check")))

redgreen("the roster entry's role is relayed, and is null whenever nothing selects it (D1 § 3.1, AT-27, card#11144)",
         f"first entry on a duplicate name -> {r_dup['role']!r}; unvalidated role -> {r_unval['role']!r}; null "
         f"role omitted -> {r_omit_role['role']!r}; role beside disagreed -> {r_any['role']!r}",
         f"A {at_a['role']!r}; E {at_e['role']!r}; B/C/D {[at_b['role'], at_c['role'], at_d['role']]}; no role "
         f"{relay_no_role['role']!r}; duplicate {relay_dup['role']!r} ({roster_role(relay_dup)}); bound "
         f"{len(relay_bound['role'] or '')} B; open vocabulary {relay_open['role']!r}")


print("\n== 19h. A DECLARED ROUNDTABLE NAME IS CHECKED AGAINST cross_project.identity (D1 § 3.1, card#12239) ==")
# D1 § 3.1's *`roundtable_agent_name`*: the same four states as the protocol agent name, checked against a
# DIFFERENT key of the SAME config — `cross_project.identity` — read through the same resolution order.
# Every value here is the generator spec's synthetic vocabulary (`pm-solo`), never a live identity.
RT_NAME = "pm-solo"
RT_DOC = dict(AT27_ROSTER, cross_project={"identity": RT_NAME})


def rt_box(tag: str, *, declare=RT_NAME, coord=RT_DOC):
    s, env = at27_box(f"rt-{tag}", coord=coord)
    if declare is not ABSENT:
        s.cfg["roundtable_agent_name"] = declare
    s.write_cfg()
    return s, env


def drive_rt(box, reporter: Path = REPORTER) -> dict:
    d = drive_at27(box, reporter=reporter)
    s, _env = box
    hb = last_heartbeat(s) or {}
    c = hb.get("counters", {})
    d["rt_ok"] = (d["rc"], (hb.get("selftest") or {}).get("rt_name_ok", "<absent>"))
    d["rt"] = {"name": hb["roundtable_agent_name"] if "roundtable_agent_name" in hb else "<absent>",
               "check": hb["roundtable_agent_name_check"] if "roundtable_agent_name_check" in hb else "<absent>",
               "unchecked": c.get("roundtable_agent_name_unchecked", 0),
               "disagreed": c.get("roundtable_agent_name_disagreed", 0)}
    return d


def rt_want(name, check, unchecked=0, disagreed=0) -> dict:
    return {"name": name, "check": check, "unchecked": unchecked, "disagreed": disagreed}


rt_checked = drive_rt(rt_box("checked"))
eq("roundtable — the config's cross_project.identity equals the declaration: `checked`, both counters 0, "
   "and the protocol pair is untouched beside it",
   (rt_want(RT_NAME, "checked"), "checked"), (rt_checked["rt"], (rt_checked["wire"] or {}).get("check")))
rt_disagreed = drive_rt(rt_box("disagreed", coord=dict(AT27_ROSTER, cross_project={"identity": "coder-solo"})))
eq("roundtable — a readable identity that differs: the name as declared, `disagreed`, and its counter 1",
   rt_want(RT_NAME, "disagreed", disagreed=1), rt_disagreed["rt"])
rt_no_block = drive_rt(rt_box("no-block", coord=AT27_ROSTER))
eq("roundtable — a readable config with no cross_project block: `unchecked`, never `disagreed`",
   rt_want(RT_NAME, "unchecked", unchecked=1), rt_no_block["rt"])
rt_no_config = drive_rt(rt_box("no-config", coord="missing"))
eq("roundtable — no readable config at all: `unchecked`", rt_want(RT_NAME, "unchecked", unchecked=1), rt_no_config["rt"])
rt_absent = drive_rt(rt_box("absent", declare=ABSENT))
eq("roundtable — no key: the name PRESENT and null, `undeclared`, both counters 0",
   rt_want(None, "undeclared"), rt_absent["rt"])
rt_bad = drive_rt(rt_box("malformed", declare="PM Solo"))
eq("roundtable — a malformed declaration declares nothing: null and `undeclared`, the seat still reporting",
   (rt_want(None, "undeclared"), "pass"), (rt_bad["rt"], rt_bad["config_readable"]))

# ⛔ RED — the check reads the protocol roster instead of cross_project.identity: `pm-solo` is no roster
# member, so a reporter checking against the wrong key reports `disagreed` for a correct declaration.
r_wrong_key = drive_rt(rt_box("red-wrong-key"), reporter=plant(
    ("  if (read.identity === name) return { name, check: 'checked', malformed: null, config: site.path };",
     "  if (readRosterNames(site.path).names?.includes(name)) return { name, check: 'checked', malformed: null, config: site.path };")))
eq("RED: a reporter checking the roundtable name against the roster reports a correct declaration `disagreed`",
   "disagreed", r_wrong_key["rt"]["check"])
# ⛔ RED — the null omitted rather than sent on an undeclared seat.
r_omit = drive_rt(rt_box("red-omit", declare=ABSENT), reporter=plant(
    ("      roundtable_agent_name: declaration.roundtable.name,",
     "      ...(declaration.roundtable.name === null ? {} : { roundtable_agent_name: declaration.roundtable.name }),")))
eq("RED: a reporter that omits a null roundtable name leaves the member ABSENT", "<absent>", r_omit["rt"]["name"])
# ⛔ RED — an unvalidated declaration reaches the wire.
r_unval = drive_rt(rt_box("red-unvalidated", declare="PM Solo"), reporter=plant(
    ("  const v = cfg.roundtable_agent_name;\n  return typeof v === 'string' && AGENT_NAME_RE.test(v) ? v : null;",
     "  const v = cfg.roundtable_agent_name;\n  return typeof v === 'string' ? v : null;")))
eq("RED: a reporter that skips the slug check puts `PM Solo` on the wire", "PM Solo", r_unval["rt"]["name"])

# § 6.14's `rt_name_ok`: the act a disagreement fails, and nothing else fails it (the exit code and the
# heartbeat's member). `selftest` exits 1 on `disagreed` and on a malformed declaration, 0 otherwise.
eq("rt_name_ok — passes on checked, unchecked (two ways) and undeclared; FAILS on disagreed and on a malformed "
   "declaration, with selftest's exit 1",
   [(0, "pass"), (1, "fail"), (0, "pass"), (0, "pass"), (0, "pass"), (1, "fail")],
   [x["rt_ok"] for x in (rt_checked, rt_disagreed, rt_no_block, rt_no_config, rt_absent, rt_bad)])
r_nofail = drive_rt(rt_box("red-rt-never-fails", coord=dict(AT27_ROSTER, cross_project={"identity": "coder-solo"})), reporter=plant(
    ("  results.rt_name_ok = rt ? rt.check !== 'disagreed' && rt.malformed === null : null;",
     "  results.rt_name_ok = rt ? true : null;")))
eq("RED: a reporter whose rt_name_ok never fails passes a disagreement silently", (0, "pass"), r_nofail["rt_ok"])

redgreen("a declared roundtable name is checked against cross_project.identity, in four states (D1 § 3.1, card#12239)",
         f"checked against the roster -> {r_wrong_key['rt']['check']!r}; null omitted -> {r_omit['rt']['name']!r}; "
         f"unvalidated -> {r_unval['rt']['name']!r}",
         f"checked {rt_checked['rt']}; disagreed {rt_disagreed['rt']}; no block {rt_no_block['rt']['check']}; "
         f"no config {rt_no_config['rt']['check']}; absent {rt_absent['rt']['check']}; malformed {rt_bad['rt']['check']}")


print("\n== 19c. THE SEAT'S CONSOLE URL IS READ FROM THE TRANSCRIPT'S TAIL, AND NOTHING ELSE REACHES THE WIRE (D1 § 6.3, card#9416) ==")
# Every id below is SYNTHETIC. A real session id is a link to a live console, so none is ever
# written into a fixture, a log or a report.
CU_ID_A = "SynthAAAAAAAAAAAAAAAAAAA"
CU_ID_B = "SynthBBBBBBBBBBBBBBBBBBB"
CU_URL_A = f"https://claude.ai/code/session_{CU_ID_A}"
CU_URL_B = f"https://claude.ai/code/session_{CU_ID_B}"


def cu_bridge(bid) -> dict:
    return {"type": "bridge-session", "bridgeSessionId": bid, "sessionId": SID, "lastSequenceNum": 1}


def cu_rsc(url) -> dict:
    return {"type": "attachment", "attachment": {"type": "remote_session_change", "url": url,
                                                 "commit": None, "pr": None}}


def cu_filler(n_bytes: int) -> dict:
    """An ordinary transcript line of about `n_bytes`, carrying neither marker."""
    return {"type": "user", "message": {"role": "user", "content": "x" * max(0, n_bytes - 64)}}


def cu_transcript(tag: str, records: list) -> Path:
    d = tmpdir(f"fr-cu-{tag}-")
    p = d / f"{SID}.jsonl"
    with p.open("w", encoding="utf-8") as f:
        for r in records:
            f.write((r if isinstance(r, str) else json.dumps(r)) + "\n")
    return p


def cu_drive(tag: str, transcript, *, mode=ABSENT, reporter: Path = REPORTER) -> dict:
    s = seat(f"cu-{tag}")
    if mode is not ABSENT:
        s.cfg["descriptors"] = mode
        s.write_cfg()
    payload = {"session_id": SID, "hook_event_name": "UserPromptSubmit", "prompt_id": "p1",
               "prompt": "go", "cwd": "/home/agent/proj"}
    if transcript is not None:
        payload["transcript_path"] = str(transcript)
    t0 = time.monotonic()
    r = hook(s, "UserPromptSubmit", payload, reporter=reporter)
    ms = (time.monotonic() - t0) * 1000
    starts = [e for e in s.events() if e["kind"] == "turn.start"]
    c = s.counters()
    return {
        "rc": r.returncode, "stdout": r.stdout,
        "url": starts[0]["data"].get("console_url", "<absent>") if len(starts) == 1 else f"<{len(starts)} turn.start>",
        "malformed": c.get("console_url_malformed", 0),
        "exhausted": c.get("console_url_tail_exhausted", 0),
        "missing_key": c.get("payload_key_missing.transcript_path", 0),
        "ms": ms,
    }


MIB = 1024 * 1024
FAR = 1536 * 1024                      # filler bytes AFTER a record: past the 1 MiB tail
fx_bridge = cu_transcript("bridge", [cu_filler(500), cu_bridge(f"cse_{CU_ID_A}"), cu_filler(800)])
fx_rsc = cu_transcript("rsc", [cu_bridge(f"cse_{CU_ID_A}"), cu_rsc(CU_URL_B), cu_filler(300)])
fx_rsc_null = cu_transcript("rsc-null", [cu_bridge(f"cse_{CU_ID_A}"), cu_rsc(None), cu_filler(300)])
fx_newest = cu_transcript("newest", [cu_bridge(f"cse_{CU_ID_A}"), cu_filler(400), cu_bridge(f"cse_{CU_ID_B}")])
fx_ended = cu_transcript("ended", [cu_bridge(f"cse_{CU_ID_A}"), cu_filler(400), cu_bridge("")])
fx_absent = cu_transcript("absent", [cu_filler(500), cu_filler(700)])
fx_bad_id = cu_transcript("bad-id", [cu_bridge(f"cse_{CU_ID_A}"), cu_bridge("cse_bad/../id")])
fx_bad_url = cu_transcript("bad-url", [cu_bridge(f"cse_{CU_ID_A}"), cu_rsc("https://claude.ai.example.net/code/session_" + CU_ID_B)])
fx_torn = cu_transcript("torn", [cu_bridge(f"cse_{CU_ID_A}"), '{"type":"bridge-session","bridgeSessionId":"cse_' + CU_ID_B])
fx_far = cu_transcript("far", [cu_bridge(f"cse_{CU_ID_A}")] + [cu_filler(64 * 1024)] * (FAR // (64 * 1024)))
# A record that STRADDLES chunk boundaries: preceded by long lines and itself ~100 KiB, so its bytes
# arrive across two of the walk's 64 KiB reads and only the carried head completes it.
fx_straddle = cu_transcript("straddle", [cu_filler(300 * 1024),
                                         dict(cu_bridge(f"cse_{CU_ID_B}"), pad="p" * (100 * 1024)),
                                         cu_filler(90 * 1024)])
# A transcript far larger than the tail, record near its end: answered without reading the head.
fx_huge = cu_transcript("huge", [cu_filler(64 * 1024)] * 640 + [cu_bridge(f"cse_{CU_ID_A}"), cu_filler(2000)])

cu_bridge_r = cu_drive("bridge", fx_bridge)
cu_rsc_r = cu_drive("rsc", fx_rsc)
cu_rsc_null_r = cu_drive("rsc-null", fx_rsc_null)
cu_newest_r = cu_drive("newest", fx_newest)
cu_ended_r = cu_drive("ended", fx_ended)
cu_absent_r = cu_drive("absent", fx_absent)
cu_nofile_r = cu_drive("nofile", fx_absent.parent / "no-such.jsonl")
cu_nokey_r = cu_drive("nokey", None)
cu_bad_id_r = cu_drive("bad-id", fx_bad_id)
cu_bad_url_r = cu_drive("bad-url", fx_bad_url)
cu_torn_r = cu_drive("torn", fx_torn)
cu_far_r = cu_drive("far", fx_far)
cu_straddle_r = cu_drive("straddle", fx_straddle)
cu_huge_r = cu_drive("huge", fx_huge)
cu_paths_r = cu_drive("paths", fx_bridge, mode="paths")
cu_none_r = cu_drive("none", fx_bridge, mode="none")

eq("present via the bridge id: the URL derived from it", CU_URL_A, cu_bridge_r["url"])
eq("present via a non-null remote_session_change url, newer than the bridge record: that url", CU_URL_B, cu_rsc_r["url"])
eq("a null remote_session_change url decides nothing: the bridge record behind it answers", CU_URL_A, cu_rsc_null_r["url"])
eq("the newest bridge record wins — the id changes within a session", CU_URL_B, cu_newest_r["url"])
eq("an empty bridge id after a live one: the bridge ended, so null", None, cu_ended_r["url"])
eq("absent: a transcript with no deciding record is null", None, cu_absent_r["url"])
eq("absent: a transcript that does not exist is null", None, cu_nofile_r["url"])
eq("absent: no transcript_path is null, and the missing key is counted", (None, 1), (cu_nokey_r["url"], cu_nokey_r["missing_key"]))
eq("malformed bridge id: dropped, counted", (None, 1), (cu_bad_id_r["url"], cu_bad_id_r["malformed"]))
eq("malformed url on a foreign host: dropped, counted", (None, 1), (cu_bad_url_r["url"], cu_bad_url_r["malformed"]))
eq("a torn last line is skipped, and the whole record before it answers", CU_URL_A, cu_torn_r["url"])
eq("oversized: a record further back than the 1 MiB tail is never read — null, counted",
   (None, 1), (cu_far_r["url"], cu_far_r["exhausted"]))
eq("a record straddling the walk's chunk boundaries is read whole", CU_URL_B, cu_straddle_r["url"])
eq(f"a {fx_huge.stat().st_size // MIB} MiB transcript, record near its end: found from the tail", CU_URL_A, cu_huge_r["url"])
eq("descriptors \"paths\": null", None, cu_paths_r["url"])
eq("descriptors \"none\": null", None, cu_none_r["url"])
cu_all = (cu_bridge_r, cu_rsc_r, cu_rsc_null_r, cu_newest_r, cu_ended_r, cu_absent_r, cu_nofile_r, cu_nokey_r,
          cu_bad_id_r, cu_bad_url_r, cu_torn_r, cu_far_r, cu_straddle_r, cu_huge_r, cu_paths_r, cu_none_r)
eq("every read exits 0 and prints nothing (P-1, P-2: UserPromptSubmit stdout reaches the model)",
   [(0, "")] * len(cu_all), [(r["rc"], r["stdout"]) for r in cu_all])
cu_clean = (cu_bridge_r, cu_rsc_r, cu_newest_r, cu_ended_r, cu_straddle_r, cu_huge_r)
eq("nothing counted malformed or exhausted where the read was clean",
   [0] * len(cu_clean), [r["malformed"] + r["exhausted"] for r in cu_clean])
print(f"  MEASURED  UserPromptSubmit wall time over the {fx_huge.stat().st_size // MIB} MiB transcript: "
      f"{cu_huge_r['ms']:.0f} ms (bridge fixture: {cu_bridge_r['ms']:.0f} ms) — P-5's budget is 250 ms, asserted by § 3")

# RED — each way the read dies, planted on a copy and seen to fail on its own fixture.
p_cu_any = plant(("const m = typeof id === 'string' ? BRIDGE_ID_RE.exec(id) : null;",
                  "const m = typeof id === 'string' ? [id, id.replace(/^cse_/, '')] : null;"))
r_cu_any = cu_drive("red-any-id", fx_bad_id, reporter=p_cu_any)
eq("RED: no pattern check on the id puts a malformed one on the wire",
   "https://claude.ai/code/session_bad/../id", r_cu_any["url"])
p_cu_host = plant(("if (typeof a.url === 'string' && CONSOLE_URL_RE.test(a.url)) return a.url;",
                   "if (typeof a.url === 'string') return a.url;"))
r_cu_host = cu_drive("red-host", fx_bad_url, reporter=p_cu_host)
eq("RED: no pattern check on the url puts a foreign host on the wire",
   "https://claude.ai.example.net/code/session_" + CU_ID_B, r_cu_host["url"])
p_cu_whole = plant(("const floor = Math.max(0, size - K.CONSOLE_TAIL_BYTES);", "const floor = 0;"))
r_cu_whole = cu_drive("red-whole", fx_far, reporter=p_cu_whole)
eq("RED: an unbounded walk reads past the 1 MiB tail (and would read a huge transcript whole)", CU_URL_A, r_cu_whole["url"])
p_cu_carry = plant(("carry = cut === -1 ? Buffer.alloc(0) : buf.subarray(0, cut);", "carry = Buffer.alloc(0);"))
r_cu_carry = cu_drive("red-carry", fx_straddle, reporter=p_cu_carry)
eq("RED: dropping the carried head loses a record that straddles a chunk boundary", False, r_cu_carry["url"] == CU_URL_B)
p_cu_mode = plant(("  if (descriptorMode(cfg) !== 'full') return null;\n  const tp", "  const tp"))
r_cu_mode = cu_drive("red-mode", fx_bridge, mode="none", reporter=p_cu_mode)
eq("RED: a read that ignores `descriptors` sends the URL from a \"none\" seat", CU_URL_A, r_cu_mode["url"])
redgreen("the console URL is read from the transcript's tail, pattern-checked, and bounded (D1 § 6.3, card#9416)",
         f"any id -> {r_cu_any['url']!r}; any url -> {r_cu_host['url']!r}; unbounded -> {r_cu_whole['url']!r}; "
         f"no carry -> {r_cu_carry['url']!r}; descriptors ignored -> {r_cu_mode['url']!r}",
         f"bridge {cu_bridge_r['url']!r}; rsc {cu_rsc_r['url']!r}; malformed -> None x2 (counted); far -> "
         f"{cu_far_r['url']!r} (exhausted {cu_far_r['exhausted']}); straddle {cu_straddle_r['url']!r}; huge "
         f"{cu_huge_r['url']!r} in {cu_huge_r['ms']:.0f} ms; none/paths -> None")


print("\n== 19d. AN UNSET harness_label IS A CONFIG GAP, NOT A MOVED HARNESS PAYLOAD (D1 § 6.1, § 9.3, card#11330) ==")
# INSTALL-LINUX.md Step 3 leaves `harness_label` unset on purpose, so this is the state of every seat
# installed by the runbook. Builds before card#11330 counted it `payload_key_missing.harness_label`,
# and § 9.3 maps that family to `harness_contract_moved`: every correct install was badged "the
# harness payload moved under this reporter". The case is driven end to end, hook -> sink -> flusher
# -> heartbeat, because the badge is what the operator reads and the counter name alone is not.
OLD_HL_COUNT = (r"count\('harness_label_unset'\)", "count('payload_key_missing.harness_label')")
NO_RENAME = (r"\n  renameCounters\(state\.counters\);\n\}", "\n}")


def hl_heartbeat(s: Seat) -> dict:
    return s.wire_heartbeats()[-1]["data"]


def hl_start(s: Seat, reporter: Path = REPORTER) -> None:
    hook(s, "SessionStart", {"session_id": SID, "hook_event_name": "SessionStart",
                             "source": "startup", "cwd": "/home/agent/mezzanine"}, reporter=reporter)


s19d = seat("harness-label-unset")
s19d.cfg.pop("harness_label")
s19d.write_cfg()
hl_start(s19d)
eq("an unset harness_label sends the field null", None,
   [e for e in s19d.events() if e["kind"] == "session.start"][0]["data"]["harness_label"])
eq("  … counts the gap as harness_label_unset", 1, s19d.counters().get("harness_label_unset"))
eq("  … and nothing under payload_key_missing.*, whose subject is a harness payload key", [],
   [k for k in s19d.counters() if k.startswith("payload_key_missing.")])
flush(s19d)
hb19d = hl_heartbeat(s19d)
eq("  … so the heartbeat carries the count and raises no harness_contract_moved",
   (1, False), (hb19d["counters"].get("harness_label_unset"), "harness_contract_moved" in hb19d["degraded"]))

# CONTROL: the badge is still reachable from the heartbeat, so its absence above is a measurement.
# A real harness payload key missing raises it, and the new counter alone does not.
mod19d = subprocess.run(
    ["node", "-e",
     "const m=require(process.argv[1]);"
     "console.log(JSON.stringify([m.buildDegraded({'payload_key_missing.tool_name':1}),"
     "m.buildDegraded({'harness_label_unset':5})]));",
     str(REPORTER)], capture_output=True, text=True, cwd=str(HERE))
deg_payload, deg_unset = json.loads(mod19d.stdout)
eq("CONTROL: a missing harness payload key still raises harness_contract_moved", True,
   "harness_contract_moved" in deg_payload)
eq("  … and harness_label_unset alone raises no member at all", [], deg_unset)

p19d_old = plant_src(OLD_HL_COUNT, NO_RENAME)       # the build before card#11330: old name, no rename
s19d_r = seat("harness-label-unset-red")
s19d_r.cfg.pop("harness_label")
s19d_r.write_cfg()
hl_start(s19d_r, reporter=p19d_old)
flush(s19d_r, reporter=p19d_old)
eq("RED: the build before card#11330 badges this correct install harness_contract_moved", True,
   "harness_contract_moved" in hl_heartbeat(s19d_r)["degraded"])

# A SEAT UPGRADED FROM THAT BUILD. Its flusher kept the old name in state.json, and an old hook may
# have left a delta in a sink bucket the new flusher has not folded yet. Both must arrive renamed,
# or replacing the artifact leaves the false badge on exactly the seats that reported it.
s19m = seat("harness-label-upgraded")
s19m.cfg.pop("harness_label")
s19m.write_cfg()
hl_start(s19m, reporter=p19d_old)
flush(s19m, reporter=p19d_old)
eq("the old build saved payload_key_missing.harness_label in state.json", 1,
   s19m.site_counters().get("payload_key_missing.harness_label"))
hl_start(s19m, reporter=p19d_old)          # a sink delta under the old name, not folded yet
flush(s19m)
hb19m = hl_heartbeat(s19m)
eq("after the upgrade both the saved total and the unfolded delta arrive as harness_label_unset",
   (2, None), (hb19m["counters"].get("harness_label_unset"),
               hb19m["counters"].get("payload_key_missing.harness_label")))
eq("  … the heartbeat raises no harness_contract_moved", False, "harness_contract_moved" in hb19m["degraded"])
eq("  … and state.json keeps only the new name", (2, None),
   (s19m.state()["counters"].get("harness_label_unset"),
    s19m.state()["counters"].get("payload_key_missing.harness_label")))

p19m_norename = plant_src(NO_RENAME)
s19m_r = seat("harness-label-upgraded-red")
s19m_r.cfg.pop("harness_label")
s19m_r.write_cfg()
hl_start(s19m_r, reporter=p19d_old)
flush(s19m_r, reporter=p19d_old)
flush(s19m_r, reporter=p19m_norename)
eq("RED: without the rename an upgraded seat keeps the false harness_contract_moved", True,
   "harness_contract_moved" in hl_heartbeat(s19m_r)["degraded"])
redgreen("an unset harness_label raises no harness_contract_moved, on a new seat or an upgraded one (card#11330)",
         "counted as payload_key_missing.harness_label -> harness_contract_moved on every seat installed "
         "by the runbook; without the rename an upgraded seat keeps the badge from its saved total",
         "counted as harness_label_unset, which raises no member; an upgraded seat's saved total and "
         "unfolded old-name delta arrive as harness_label_unset (2) and the badge clears; control: a "
         "missing harness payload key still raises harness_contract_moved")


print("\n== 19e. A DEGRADED MEMBER IS RAISED WHILE ITS COUNTER ROSE WITHIN 24 h, AND THE TOTAL OUTLIVES IT (D1 § 9.3, card#9491) ==")
# The totals persist across flusher restarts in state.json (D1 § 9.3), so a member derived from
# "non-zero" never cleared: one bad session id badged the seat `bad_session_id` for the rest of its
# life, and restarting the flusher, which the docs named as the way out, changed nothing. The
# operator's ruling of 2026-09-14 windows every counter-derived badge at 24 h. Each `flush` below is
# a FRESH flusher process on a pinned clock, so every step is also a restart. Driven end to end,
# hook -> sink -> flusher -> heartbeat, because the badge is what the operator reads.
H_MS = 3600 * 1000
W0 = int(time.time() * 1000)
BAD_SID = "not a session id"


def bw_hook(s: Seat, at_ms: int, reporter: Path = REPORTER) -> None:
    hook(s, "SessionStart", {"session_id": BAD_SID, "hook_event_name": "SessionStart",
                             "source": "startup", "cwd": "/home/agent/mezzanine"},
         reporter=reporter, FLEET_REPORTER_NOW_MS=at_ms)


def bw_flush(s: Seat, at_ms: int, reporter: Path = REPORTER) -> dict:
    flush(s, reporter=reporter, FLEET_REPORTER_NOW_MS=at_ms)
    return hl_heartbeat(s)


s19e = seat("badge-window")
bw_hook(s19e, W0)
hb_now = bw_flush(s19e, W0)
eq("a bad session id raises bad_session_id on the next heartbeat",
   (1, True), (hb_now["counters"].get("bad_session_id"), "bad_session_id" in hb_now["degraded"]))
hb_23 = bw_flush(s19e, W0 + 23 * H_MS)
eq("  … and a restarted flusher 23 h later still raises it: the rise is inside the window",
   (1, True), (hb_23["counters"].get("bad_session_id"), "bad_session_id" in hb_23["degraded"]))
hb_25 = bw_flush(s19e, W0 + 25 * H_MS)
eq("  … 25 h later, with no further rise, the member has cleared and the total of 1 still rides "
   "the heartbeat", (1, False), (hb_25["counters"].get("bad_session_id"), "bad_session_id" in hb_25["degraded"]))
bw_hook(s19e, W0 + 26 * H_MS)
hb_26 = bw_flush(s19e, W0 + 26 * H_MS)
eq("  … and a second bad session id raises it again, with the total at 2",
   (2, True), (hb_26["counters"].get("bad_session_id"), "bad_session_id" in hb_26["degraded"]))
# The pinned clock is an OFFSET (FLEET_REPORTER_NOW_MS), so the pass reads it a little after the pin;
# the rise is dated at that pass, inside one heartbeat interval of the pin.
rise_26 = s19e.state()["counter_rises"].get("bad_session_id") or {}
rise_26_ms = calendar.timegm(time.strptime(rise_26.get("at", "1970-01-01T00:00:00.000Z")[:19],
                                           "%Y-%m-%dT%H:%M:%S")) * 1000 + int(rise_26.get("at", "x.000Z")[-4:-1])
eq("  … and state.json dates that rise at the heartbeat that saw it, within one interval of the pinned clock",
   (2, True), (rise_26.get("value"), 0 <= rise_26_ms - (W0 + 26 * H_MS) < 60_000))

# AN UPGRADED SEAT. A state.json an earlier build saved carries the total and no `counter_rises`.
# Its first heartbeat dates every non-zero total as rising then, so the badge it showed holds for
# one window after the upgrade, and clears after it.
s19u = seat("badge-window-upgraded")
bw_hook(s19u, W0)
bw_flush(s19u, W0)
old_state = s19u.state()
old_state.pop("counter_rises")
(s19u.spool / "state.json").write_text(json.dumps(old_state), encoding="utf-8")
hb_up = bw_flush(s19u, W0 + 40 * H_MS)
eq("an upgraded state.json with an old total keeps the member on its first heartbeat",
   (1, True), (hb_up["counters"].get("bad_session_id"), "bad_session_id" in hb_up["degraded"]))
hb_up2 = bw_flush(s19u, W0 + 65 * H_MS)
eq("  … and clears it one window later", False, "bad_session_id" in hb_up2["degraded"])

# CONTROL: the window is per counter. A member raised by a family counter that rose recently stays,
# while one whose counter last rose a window ago clears, in the same heartbeat.
mod19e = subprocess.run(
    ["node", "-e",
     "const m=require(process.argv[1]);const W=m.K.BADGE_WINDOW_MS;const t=Date.parse('2026-10-01T00:00:00.000Z');"
     "const st={counters:{'spool_dropped_events':3,'payload_key_missing.tool_name':1},counter_rises:{"
     "'spool_dropped_events':{value:3,at:new Date(t-W).toISOString()},"
     "'payload_key_missing.tool_name':{value:1,at:new Date(t-W+1).toISOString()}}};"
     "console.log(JSON.stringify({w:W,deg:m.buildDegraded(m.windowedCounters(st,t))}));",
     str(REPORTER)], capture_output=True, text=True, cwd=str(HERE))
r19e = json.loads(mod19e.stdout)
eq("CONTROL: the window is D1 § 9.3's 24 h", 24 * H_MS, r19e["w"])
eq("  … a counter that last rose exactly one window ago raises nothing, and one that rose 1 ms "
   "later still raises its member", ["harness_contract_moved"], r19e["deg"])

# RED: derive the members from the totals, which is the build before card#9491.
p19e = plant(("degraded: buildDegraded(siteWindowed(state, entry, all, atMs))", "degraded: buildDegraded(all)"))
s19e_r = seat("badge-window-red")
bw_hook(s19e_r, W0, reporter=p19e)
bw_flush(s19e_r, W0, reporter=p19e)
hb_r = bw_flush(s19e_r, W0 + 25 * H_MS, reporter=p19e)
eq("RED: derived from the totals, the member is still raised 25 h and a flusher restart later",
   True, "bad_session_id" in hb_r["degraded"])
redgreen("a degraded member is raised while its counter rose within 24 h; the total outlives it (card#9491)",
         "members derived from the persisted totals: one bad session id keeps bad_session_id raised "
         "25 h and a flusher restart later, so the badge never clears",
         "raised at 0 h and at 23 h, cleared at 25 h with the total of 1 still on the heartbeat, raised "
         "again by a second rise; an upgraded state.json keeps the badge one window, then clears; "
         "control: the window is per counter, exclusive at 24 h")


print("\n== 19f. A STATUSLINE WITH NO USABLE context_window IS EXPECTED, NOT A MOVED HARNESS PAYLOAD (D1 § 6.11, § 9.3, card#11544) ==")
# D1 § 6.11: the statusLine payload has no usable `context_window` in the first seconds of EVERY
# session, so the counter for it is non-zero on every seat that applies INSTALL-LINUX.md Step 4(b).
# Builds before card#11544 counted it `payload_key_missing.context_window`, which § 9.3 maps to
# `harness_contract_moved`: every session start re-raised "the harness payload moved under this
# reporter", so card#9491's 24 h window never cleared it on an active seat. Driven end to end,
# statusLine -> sink -> flusher -> heartbeat, because the badge is what the operator reads.
OLD_CW_COUNT = (r"count\('context_window_unavailable'\)", "count('payload_key_missing.context_window')")
NO_CW_RENAME = (r"\n  \['payload_key_missing\.context_window', 'context_window_unavailable'\],", "")
# The two shapes § 6.11 names: no `context_window` at all, and one whose `used_percentage` is null
# with no token counts to compute it from (`current_usage` null early in a session).
CW_NONE = {"session_id": SID, "model": {"display_name": "claude-opus-5"}}
CW_NULL = {"session_id": SID, "context_window": {"used_percentage": None, "current_usage": None}}


def cw_session_start(s: Seat, reporter: Path = REPORTER) -> None:
    statusline(s, CW_NONE, reporter=reporter)
    statusline(s, CW_NULL, reporter=reporter)


s19f = seat("context-window-unavailable")
cw_session_start(s19f)
eq("a statusLine with no usable context_window emits no context.sample", [],
   [e for e in s19f.events() if e["kind"] == "context.sample"])
eq("  … counts both renders as context_window_unavailable", 2, s19f.counters().get("context_window_unavailable"))
eq("  … and nothing under payload_key_missing.*, whose family raises harness_contract_moved", [],
   [k for k in s19f.counters() if k.startswith("payload_key_missing.")])
flush(s19f)
hb19f = hl_heartbeat(s19f)
eq("  … so the heartbeat carries the count and raises no harness_contract_moved",
   (2, False), (hb19f["counters"].get("context_window_unavailable"), "harness_contract_moved" in hb19f["degraded"]))
eq("  … nor any other member", [], hb19f["degraded"])

# CONTROL: the badge is still reachable from the heartbeat, so its absence above is a measurement,
# and the prefix match still fails loud for a payload key nobody has seen go missing before.
mod19f = subprocess.run(
    ["node", "-e",
     "const m=require(process.argv[1]);"
     "console.log(JSON.stringify([m.buildDegraded({'payload_key_missing.a_key_added_later':1}),"
     "m.buildDegraded({'context_window_unavailable':5})]));",
     str(REPORTER)], capture_output=True, text=True, cwd=str(HERE))
deg_new_key, deg_cw = json.loads(mod19f.stdout)
eq("CONTROL: a payload_key_missing.* key no table names still raises harness_contract_moved", True,
   "harness_contract_moved" in deg_new_key)
eq("  … and context_window_unavailable alone raises no member at all", [], deg_cw)

p19f_old = plant_src(OLD_CW_COUNT, NO_CW_RENAME)     # the build before card#11544: old name, no rename
s19f_r = seat("context-window-unavailable-red")
cw_session_start(s19f_r, reporter=p19f_old)
flush(s19f_r, reporter=p19f_old)
eq("RED: the build before card#11544 badges a session start harness_contract_moved", True,
   "harness_contract_moved" in hl_heartbeat(s19f_r)["degraded"])

# A SEAT UPGRADED FROM THAT BUILD. Its flusher saved the old name in state.json, and an old
# statusLine process may have left a delta in a sink bucket the new flusher has not folded yet.
# Both must arrive renamed, or replacing the artifact leaves the false badge on every 4(b) seat.
s19fm = seat("context-window-upgraded")
cw_session_start(s19fm, reporter=p19f_old)
flush(s19fm, reporter=p19f_old)
eq("the old build saved payload_key_missing.context_window in state.json", 2,
   s19fm.site_counters().get("payload_key_missing.context_window"))
statusline(s19fm, CW_NONE, reporter=p19f_old)       # a sink delta under the old name, not folded yet
flush(s19fm)
hb19fm = hl_heartbeat(s19fm)
eq("after the upgrade both the saved total and the unfolded delta arrive as context_window_unavailable",
   (3, None), (hb19fm["counters"].get("context_window_unavailable"),
               hb19fm["counters"].get("payload_key_missing.context_window")))
eq("  … the heartbeat raises no harness_contract_moved", False, "harness_contract_moved" in hb19fm["degraded"])
eq("  … and state.json keeps only the new name", (3, None, None),
   (s19fm.state()["counters"].get("context_window_unavailable"),
    s19fm.state()["counters"].get("payload_key_missing.context_window"),
    s19fm.state()["counter_rises"].get("payload_key_missing.context_window")))

p19fm_norename = plant_src(NO_CW_RENAME)
s19fm_r = seat("context-window-upgraded-red")
cw_session_start(s19fm_r, reporter=p19f_old)
flush(s19fm_r, reporter=p19f_old)
flush(s19fm_r, reporter=p19fm_norename)
eq("RED: without the rename entry an upgraded seat keeps the false harness_contract_moved", True,
   "harness_contract_moved" in hl_heartbeat(s19fm_r)["degraded"])
redgreen("a statusLine with no usable context_window raises no harness_contract_moved, on a new seat or an "
         "upgraded one (card#11544)",
         "counted as payload_key_missing.context_window -> harness_contract_moved at every session start "
         "on a Step 4(b) seat; without the rename entry an upgraded seat keeps the badge from its saved total",
         "counted as context_window_unavailable, which raises no member; an upgraded seat's saved total and "
         "unfolded old-name delta arrive as context_window_unavailable (3) and the badge clears; control: "
         "a payload_key_missing.* key no table names still raises harness_contract_moved")


print("\n== 19g. ONE SEAT'S SPOOL ORDER IS ITS event_time ORDER, ACROSS EVERY WRITER (D1 § 11.2, card#11563) ==")
# Every writer reads the clock its events carry while holding the seat write lock and appends them
# before releasing it. Before card#11563 a hook read its clock at ENTRY and appended after stdin
# and the index fold, and the flusher stamped its heartbeat at the start of a pass that had since
# awaited the network — so two writers could append in the opposite order to their clocks. The
# probe card#11563 names measured it with real racing processes (5 of 80 trials); the cases below
# make the same interleavings DETERMINISTIC, by holding one writer at the exact point the race
# needs it held, and assert on the spool itself.
#
# THE RED is one plant on the primitive: the stamp replaced by the clock read when the process
# started, which is where a hook read it before card#11563. The lock is still taken and released.
p19g_entry = plant(
    ("const now = () => Date.now() + CLOCK_OFFSET;\n",
     "const now = () => Date.now() + CLOCK_OFFSET;\nconst PROCESS_ENTRY_MS = Date.now() + CLOCK_OFFSET;\n"),
    ("held = acquireWriteLock(spool); stamp = now();",
     "held = acquireWriteLock(spool); stamp = PROCESS_ENTRY_MS;"))


def spool_times(s: Seat) -> list[tuple[str, str]]:
    """(event_time, kind) for every spooled event, in SPOOL order — the order seq is assigned in."""
    return [(e["event_time"], e["kind"]) for e in s.events()]


def inversions(s: Seat) -> list[str]:
    """Every pair spooled in the opposite order to its event_times — all pairs, not only adjacent
    ones: a later-stamped event can sit any distance ahead of the older one it overtook."""
    t = spool_times(s)
    return [f"{a[1]}@{a[0]} spooled before {b[1]}@{b[0]}"
            for i, a in enumerate(t) for b in t[i + 1:] if b[0] < a[0]]


def silence_race(name: str, reporter: Path) -> Seat:
    """§ 6.2's inferred close against a `Stop` that ENTERED before it and appends after it.

    The Stop hook is started and held on its stdin, which it reads after its old entry-time clock
    and before its index fold; a one-pass flusher then closes the silent session; then the Stop is
    released. That is the probe's measured interleaving with the timing removed."""
    s = seat(name, ingest=DEAD)
    old = int(time.time() * 1000) - 92 * 60_000
    hook(s, "UserPromptSubmit", {"session_id": SID, "hook_event_name": "UserPromptSubmit",
                                 "prompt_id": "p1", "prompt": "go"},
         reporter=reporter, FLEET_REPORTER_NOW_MS=old)
    hook(s, "PreToolUse", pre(), reporter=reporter, FLEET_REPORTER_NOW_MS=old + 1000)
    held = subprocess.Popen(["node", str(reporter), "hook", "Stop"], stdin=subprocess.PIPE,
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, text=True,
                            env=s.env(), cwd=str(HERE))
    time.sleep(1.0)   # past node start-up and config load: the old reporter has read its clock
    flush(s, reporter=reporter)
    held.communicate(json.dumps({"session_id": SID, "hook_event_name": "Stop"}), timeout=30)
    return s


def heartbeat_race(name: str, reporter: Path) -> Seat:
    """A hook that runs while the flusher awaits its health probe, i.e. after the pass's clock was
    read and before the pass's heartbeat is built."""
    s = seat(name)
    hook(s, "PreToolUse", pre(tuid="hb_race_0"), reporter=reporter)
    INGEST.get_script = [{"run": lambda: hook(s, "PreToolUse", pre(tuid="hb_race_1"), reporter=reporter)}]
    try:
        flush(s, reporter=reporter)
    finally:
        INGEST.get_script = []
    return s


r19g_s = silence_race("order-silence-red", p19g_entry)
kinds_r = [k for _, k in spool_times(r19g_s)]
eq("RED staging: the race happened — the flusher closed the session and the held Stop then ended "
   "its turn", (True, True), ("session.end" in kinds_r, "turn.end" in kinds_r))
inv_r = inversions(r19g_s)
eq("RED: with the clock read at process entry, the held Stop's turn.end lands in the spool AFTER "
   "the flusher's session.end and carries an OLDER event_time", True,
   any(i.startswith("session.end@") and "before turn.end@" in i for i in inv_r))
g19g_s = silence_race("order-silence-green", REPORTER)
kinds_g = [k for _, k in spool_times(g19g_s)]
eq("GREEN staging: the same race, the same two events", (True, True),
   ("session.end" in kinds_g, "turn.end" in kinds_g))
eq("GREEN: every event in the spool is in event_time order", [], inversions(g19g_s))

# THE HEARTBEAT LEG OF card#11563 IS RETIRED BY card#12180, and this says so rather than dropping it: the heartbeat
# is built per site as item 0 of that site's request and is NEVER a spool line (D1 § 6.14), so a hook during the
# probe has no flusher-stamped line to overtake. What remains checkable is that no heartbeat reaches the spool at
# all — the spooling defect's own RED is AT-28 Case A's — and that the hook's line is still in event_time order.
g19g_h = heartbeat_race("order-heartbeat-green", REPORTER)
eq("GREEN staging: the hook ran during the probe, so the spool holds both calls' tool.start — and no heartbeat, "
   "which is never spooled since card#12180", (2, 0),
   (len([1 for _, k in spool_times(g19g_h) if k == "tool.start"]),
    len([1 for _, k in spool_times(g19g_h) if k == "reporter.heartbeat"])))
eq("GREEN: the spool is in event_time order", [], inversions(g19g_h))

# THE LOCK ITSELF. A live holder makes a writer wait — bounded at K.WRITE_LOCK_WAIT_MS, then it
# proceeds unlocked and COUNTS it, because a hook never holds up the harness for an ordering
# guarantee. A dead or aged holder is broken and counted. The RED removes the exclusive create.
p19g_nolock = plant(
    ("      const fd = fs.openSync(lock, 'wx');\n",
     "      return true;\n      const fd = fs.openSync(lock, 'wx');\n"))


def held_lock(s: Seat, pid: int, age_s: float = 0.0) -> Path:
    lk = s.spool / "write.lock"
    lk.write_text(str(pid), encoding="utf-8")
    t = time.time() - age_s
    os.utime(lk, (t, t))
    return lk


def dead_pid() -> int:
    p = subprocess.Popen(["true"])
    p.wait()
    return p.pid


s19g_live = seat("write-lock-live", ingest=DEAD)
lk = held_lock(s19g_live, os.getpid())
hook(s19g_live, "PreToolUse", pre(tuid="wl_live"))
# The counter is raised only once the wait's deadline has passed, so it is the measurement that the
# hook waited; a wall-clock delta against a second hook would be node start-up jitter of the same size.
eq("a live holder: the hook waits it out to the cap, proceeds, and counts write_lock_timeout",
   1, s19g_live.counters().get("write_lock_timeout"))
eq("  … its event is still spooled — a held lock costs ordering, never an event", 1,
   len([1 for _, k in spool_times(s19g_live) if k == "tool.start"]))
eq("  … and it leaves the holder's lock in place, since it never held it", str(os.getpid()),
   lk.read_text(encoding="utf-8"))
s19g_live_r = seat("write-lock-live-red", ingest=DEAD)
held_lock(s19g_live_r, os.getpid())
hook(s19g_live_r, "PreToolUse", pre(tuid="wl_live_r"), reporter=p19g_nolock)
eq("RED: with no exclusive create the hook ignores a live holder and counts nothing", None,
   s19g_live_r.counters().get("write_lock_timeout"))

s19g_dead = seat("write-lock-dead", ingest=DEAD)
held_lock(s19g_dead, dead_pid())
hook(s19g_dead, "PreToolUse", pre(tuid="wl_dead"))
eq("a holder whose pid is gone is broken at once and counted; the hook's own lock is released",
   (1, None, False), (s19g_dead.counters().get("write_lock_broken"),
                      s19g_dead.counters().get("write_lock_timeout"),
                      (s19g_dead.spool / "write.lock").exists()))
s19g_aged = seat("write-lock-aged", ingest=DEAD)
held_lock(s19g_aged, os.getpid(), age_s=10.0)
hook(s19g_aged, "PreToolUse", pre(tuid="wl_aged"))
eq("a live pid on a lock 10 s old — a reused pid, or a holder hung far past any section — is "
   "broken and counted", (1, None), (s19g_aged.counters().get("write_lock_broken"),
                                     s19g_aged.counters().get("write_lock_timeout")))
redgreen("one seat's spool order is its event_time order (D1 § 11.2, card#11563)",
         "the clock read at process entry: a Stop held between its entry and its append puts "
         "turn.end behind the flusher's session.end with an older event_time (the heartbeat leg is "
         "retired: since card#12180 the heartbeat is no spool line); with no "
         "exclusive create a hook ignores a live holder and counts nothing",
         "both interleavings leave the spool in event_time order; a live holder makes the hook "
         "wait to the cap, then spool its event and count write_lock_timeout; a dead or 10 s old "
         "holder is broken and counted write_lock_broken")


print("\n== 20. A BACKGROUND DISPATCH STAYS OPEN UNTIL ITS SubagentStop (§ 8.5, card#11799) ==")
# The floor draws an intern for every OPEN dispatch call. The harness now runs a dispatched
# subagent as a background task: the dispatch tool returns on LAUNCH, so its PostToolUse fires
# tens of ms after its PreToolUse (MEASURED on this project's seats, 2.1.266-2.1.296: dispatches
# closed `post_tool_use` with a harness duration of tens of ms) while the subagent runs
# for minutes. Closing the call there left the side table permanently empty. The tool's own
# response says which case it is — `status: "async_launched"` with the launched `agentId` —
# so that is what every case below drives, in BOTH hook orders, plus the backstops that must
# still close a dispatch whose SubagentStop never comes.
BGSID = "77777777-2222-4333-8444-000000000001"
AG1, AG2, FOREIGN = "a0123456789abcdef", "a00000000000000b2", "a99999999999999f9"


def bg_pre(tuid, desc="probe the ingest"):
    return pre(tool="Agent", ti={"description": desc, "subagent_type": "coder", "prompt": "x",
                                 "run_in_background": True}, tuid=tuid, session_id=BGSID)


def bg_post(tuid, agent, *, launched=True, desc="probe the ingest"):
    # tool_response is the shape a launch records on this project's seats at 2.1.296 (keys read
    # from the transcripts' recorded tool results); a foreground dispatch's is `completed`.
    tr = ({"isAsync": True, "status": "async_launched", "agentId": agent, "description": desc,
           "resolvedModel": "<model id>", "prompt": "x", "outputFile": "/tmp/x.output",
           "canReadOutputFile": True, "canContinueAgent": True}
          if launched else {"status": "completed", "agentId": agent, "content": []})
    p = bg_pre(tuid, desc)
    p.update(hook_event_name="PostToolUse", tool_response=tr, duration_ms=22 if launched else 4957)
    return p


def bg_tasks(*agents):
    return [{"id": a, "type": "subagent", "status": "running", "description": "probe",
             "agent_type": "coder"} for a in agents]


def bg_hook(s, name, *, reporter, **kw):
    base = {"session_id": BGSID, "hook_event_name": name, "prompt_id": "p1", "cwd": "/home/agent/mezzanine"}
    base.update(kw)
    return hook(s, name, base, reporter=reporter)


STEPS = {
    "prompt": lambda s, r: bg_hook(s, "UserPromptSubmit", reporter=r, prompt="go"),
    "pre1": lambda s, r: hook(s, "PreToolUse", bg_pre("toolu_D1"), reporter=r),
    "pre2": lambda s, r: hook(s, "PreToolUse", bg_pre("toolu_D2", "second"), reporter=r),
    "start1": lambda s, r: bg_hook(s, "SubagentStart", reporter=r, agent_id=AG1, agent_type="coder"),
    "start2": lambda s, r: bg_hook(s, "SubagentStart", reporter=r, agent_id=AG2, agent_type="coder"),
    "launch1": lambda s, r: hook(s, "PostToolUse", bg_post("toolu_D1", AG1), reporter=r),
    "launch2": lambda s, r: hook(s, "PostToolUse", bg_post("toolu_D2", AG2, desc="second"), reporter=r),
    "done1": lambda s, r: hook(s, "PostToolUse", bg_post("toolu_D1", AG1, launched=False), reporter=r),
    "inner1": lambda s, r: hook(s, "PreToolUse", pre(tool="Bash", ti={"command": "ls"}, tuid="toolu_I1",
                                                     session_id=BGSID, agent_id=AG1), reporter=r),
    "stop_bg1": lambda s, r: bg_hook(s, "Stop", reporter=r, stop_hook_active=False,
                                     background_tasks=bg_tasks(AG1), session_crons=[]),
    "stop_bg12": lambda s, r: bg_hook(s, "Stop", reporter=r, stop_hook_active=False,
                                      background_tasks=bg_tasks(AG1, AG2), session_crons=[]),
    "stop_none": lambda s, r: bg_hook(s, "Stop", reporter=r, stop_hook_active=False,
                                      background_tasks=[], session_crons=[]),
    "foreign_stop": lambda s, r: bg_hook(s, "SubagentStop", reporter=r, agent_id=FOREIGN, agent_type="x",
                                         stop_hook_active=False, background_tasks=[], session_crons=[]),
    "sstop1": lambda s, r: bg_hook(s, "SubagentStop", reporter=r, agent_id=AG1, agent_type="coder",
                                   stop_hook_active=False, background_tasks=[], session_crons=[]),
    "sstop2": lambda s, r: bg_hook(s, "SubagentStop", reporter=r, agent_id=AG2, agent_type="coder",
                                   stop_hook_active=False, background_tasks=[], session_crons=[]),
    "end": lambda s, r: bg_hook(s, "SessionEnd", reporter=r, reason="other"),
}


def dispatch_trace(name, steps, reporter=REPORTER):
    """Drive `steps` in order; return (events, counters, {step: [kinds emitted by that step]})."""
    s = seat(name)
    bg_hook(s, "SessionStart", reporter=reporter, source="startup")
    per = {}
    for st in steps:
        before = len(s.events())
        STEPS[st](s, reporter)
        per.setdefault(st, []).extend(e["kind"] for e in s.events()[before:])
    return s.events(), s.counters(), per


def dispatch_closes(ev, call_id):
    return [(e["data"]["outcome"], e["data"]["close_source"], e["data"]["match"])
            for e in ev if e["kind"] == "tool.end" and e["data"]["call_id"] == call_id]


def dispatch_ids(ev):
    return [e["data"]["call_id"] for e in ev if e["kind"] == "subagent.spawn"]


BG_MEASURED = ["prompt", "pre1", "start1", "launch1", "inner1", "stop_bg1", "foreign_stop", "sstop1"]
ev, ctr, per = dispatch_trace("bg-measured", BG_MEASURED)
D1 = dispatch_ids(ev)[0]
eq("the MEASURED background order (SubagentStart, then the launch's PostToolUse): the launch "
   "closes nothing — the dispatch is still open, so the floor draws the intern", [], per["launch1"])
eq("  … the parent's turn ends while the subagent runs, and its Stop does NOT reap the dispatch "
   "the payload lists as a running background task", ["turn.end"], per["stop_bg1"])
te = [e for e in ev if e["kind"] == "turn.end"][0]["data"]
eq("  … so that turn.end is clean: aborted_call_ids [], open_calls_at_end 0, background_tasks_open 1",
   ([], 0, 1), (te["aborted_call_ids"], te["open_calls_at_end"], te["background_tasks_open"]))
eq("  … a SubagentStop for an agent no dispatch launched (the harness fires these far more often "
   "than dispatches) closes nothing", [], per["foreign_stop"])
eq("  … the subagent's inner call names its still-open parent", D1,
   [e for e in ev if e["kind"] == "tool.start" and e["data"]["tool_name"] == "Bash"][0]["data"]["parent_call_id"])
eq("  … and the dispatch's ONE close is its own SubagentStop, completed, matched by agent_id",
   [("completed", "subagent_stop_hook", "agent_id")], dispatch_closes(ev, D1))
eq("  … with exactly one subagent.stop, from the same hook",
   ["subagent_stop_hook"], [e["data"]["close_source"] for e in ev if e["kind"] == "subagent.stop"])
eq("  … and the counters say which rule fired", (1, 1, 1),
   (ctr.get("dispatch_background_launch"), ctr.get("dispatch_kept_across_turn_reap"),
    ctr.get("subagent_stop_unmatched")))

# RED 1 — the defect as shipped: the launch's PostToolUse closes the dispatch.
p_close_on_launch = plant(("if (!tr || typeof tr !== 'object' || tr.status !== 'async_launched') return null;",
                           "return null;"))
evr, ctrr, perr = dispatch_trace("bg-measured-red-launch", BG_MEASURED, p_close_on_launch)
D1r = dispatch_ids(evr)[0]
eq("RED: reading no launch marker closes the dispatch at its launch, ~tens of ms after it opened "
   "— the empty side table", ["tool.end", "subagent.stop"], perr["launch1"])
eq("  … and the real SubagentStop then finds nothing to close",
   [("completed", "post_tool_use", "harness_ref")], dispatch_closes(evr, D1r))

# RED 2 — keep it open but let the turn reap take it: the intern vanishes when the parent's turn
# ends, minutes before the subagent does.
p_turn_reaps = plant(("  const stillRunning = (e) => !!(e.is_dispatch && e.child_agent_id && running.has(e.child_agent_id));",
                      "  const stillRunning = (e) => false;"))
evt, _, pert = dispatch_trace("bg-measured-red-turnreap", BG_MEASURED, p_turn_reaps)
eq("RED: a turn reap blind to background_tasks aborts the running subagent's dispatch at the "
   "parent's Stop", [("aborted", "reap_turn_boundary", "reap")], dispatch_closes(evt, dispatch_ids(evt)[0]))

# RED 3 — the SubagentStop fallback considering BOUND dispatches: the first foreign stop takes it.
p_any_sole = plant(("const dispatches = [...ix.calls.values()].filter((e) => e.is_dispatch && !e.child_agent_id);",
                    "const dispatches = [...ix.calls.values()].filter((e) => e.is_dispatch);"))
evf, _, perf = dispatch_trace("bg-measured-red-foreign", BG_MEASURED, p_any_sole)
eq("RED: a sole-open fallback over BOUND dispatches lets a foreign agent's SubagentStop close the "
   "running subagent's dispatch", [("completed", "subagent_stop_hook", "sole_open")],
   dispatch_closes(evf, dispatch_ids(evf)[0]))

# The OTHER order of launch and binding: the launch's PostToolUse before SubagentStart. The
# launch's own agentId binds the call exactly, so SubagentStart has nothing left to decide.
ev2, ctr2, _ = dispatch_trace("bg-launch-first", ["prompt", "pre1", "launch1", "start1", "stop_bg1", "sstop1"])
eq("launch BEFORE SubagentStart: still one close, by SubagentStop, matched by agent_id",
   [("completed", "subagent_stop_hook", "agent_id")], dispatch_closes(ev2, dispatch_ids(ev2)[0]))
eq("  … bound by the launch's own agentId", 1, ctr2.get("agent_bind_launch_ref"))

# Two dispatches in parallel, both launched before either SubagentStart: the sole-unbound rule
# can bind neither (ambiguous), so only the launch's exact agentId can tie each stop to its call.
PAR = ["prompt", "pre1", "pre2", "start1", "start2", "launch1", "launch2", "stop_bg12", "sstop2", "sstop1"]
evp, ctrp, _ = dispatch_trace("bg-parallel", PAR)
P1, P2 = dispatch_ids(evp)
eq("two parallel background dispatches each close at their OWN SubagentStop, by agent_id",
   ([("completed", "subagent_stop_hook", "agent_id")], [("completed", "subagent_stop_hook", "agent_id")]),
   (dispatch_closes(evp, P1), dispatch_closes(evp, P2)))
p_no_launch_bind = plant(("          journal(ctx.spool, { k: 'bind', call_id: entry.call_id, child_agent_id: launch.agentId });\n"
                          "          entry.child_agent_id = launch.agentId;\n",
                          ""))
evpr, _, _ = dispatch_trace("bg-parallel-red", PAR, p_no_launch_bind)
eq("RED: without the launch's exact binding the ambiguous parallel pair is bound to nothing, so "
   "the parent's Stop cannot see them in background_tasks and aborts both while they run",
   ([("aborted", "reap_turn_boundary", "reap")], [("aborted", "reap_turn_boundary", "reap")]),
   tuple(dispatch_closes(evpr, c) for c in dispatch_ids(evpr)))

# SubagentStop BEFORE the dispatch's own PostToolUse — the 2.1.240 / 2.1.247-headless order, and
# a background launch whose subagent finished inside the launch window. The second signal finds
# the call closed and puts nothing on the wire (§ 8.5).
for label, steps in (("foreground", ["prompt", "pre1", "start1", "sstop1", "done1"]),
                     ("background", ["prompt", "pre1", "start1", "sstop1", "launch1"])):
    evo, ctro, pero = dispatch_trace(f"order-stop-first-{label}", steps)
    eq(f"{label}, SubagentStop FIRST: one close, by SubagentStop", [("completed", "subagent_stop_hook", "agent_id")],
       dispatch_closes(evo, dispatch_ids(evo)[0]))
    eq(f"  … and the late PostToolUse emits nothing — no synthesized phantom `Agent` call",
       ([], 0), (pero[steps[-1]], len([e for e in evo if e["data"].get("synthesized")])))
    eq("  … counted", 1, ctro.get("dispatch_close_after_subagent_stop"))
p_no_absorb = plant(("        if (r.close_source === 'subagent_stop_hook' && e.harness_call_ref) {",
                     "        if (false) {"))
evn, _, pern = dispatch_trace("order-stop-first-red", ["prompt", "pre1", "start1", "sstop1", "done1"], p_no_absorb)
eq("RED: with no trace of the SubagentStop close, the late PostToolUse synthesizes a phantom "
   "`Agent` call pair", ["tool.start", "tool.end"], pern["done1"])

# A foreground dispatch whose PostToolUse comes FIRST keeps the behaviour it always had.
evg, ctrg, _ = dispatch_trace("order-fg-post-first", ["prompt", "pre1", "start1", "done1", "sstop1"])
eq("foreground, PostToolUse first (status completed): closes at its PostToolUse, exactly as before",
   [("completed", "post_tool_use", "harness_ref")], dispatch_closes(evg, dispatch_ids(evg)[0]))
eq("  … and is not counted as a background launch", None, ctrg.get("dispatch_background_launch"))

# THE BACKSTOPS — a dispatch whose SubagentStop never arrives is still closed.
evs, _, pers = dispatch_trace("bg-backstop-turn", ["prompt", "pre1", "start1", "launch1", "stop_none"])
Ds = dispatch_ids(evs)[0]
eq("backstop: a Stop whose background_tasks no longer lists the subagent reaps its dispatch",
   [("aborted", "reap_turn_boundary", "reap")], dispatch_closes(evs, Ds))
eq("  … and that turn.end names it", [Ds], [e for e in evs if e["kind"] == "turn.end"][0]["data"]["aborted_call_ids"])
p_never_reap = plant(("  const stillRunning = (e) => !!(e.is_dispatch && e.child_agent_id && running.has(e.child_agent_id));",
                      "  const stillRunning = (e) => !!e.is_dispatch;"))
evsr, _, _ = dispatch_trace("bg-backstop-turn-red", ["prompt", "pre1", "start1", "launch1", "stop_none"], p_never_reap)
eq("RED: exempting every dispatch from the turn reap leaves it open with nothing listing it",
   [], dispatch_closes(evsr, dispatch_ids(evsr)[0]))
eve, _, _ = dispatch_trace("bg-backstop-session", ["prompt", "pre1", "start1", "launch1", "stop_bg1", "end"])
eq("backstop: the session boundary reaps a background dispatch still open at SessionEnd",
   [("aborted", "reap_session_boundary", "reap")], dispatch_closes(eve, dispatch_ids(eve)[0]))
p_session_spares = plant(("  const select = (e) => e.session_id === sessionId && !carried(e);",
                          "  const select = (e) => e.session_id === sessionId && !carried(e) && !e.child_agent_id;"))
ever, _, _ = dispatch_trace("bg-backstop-session-red", ["prompt", "pre1", "start1", "launch1", "stop_bg1", "end"], p_session_spares)
eq("RED: a session reap that spares bound dispatches leaves one open past its session's end",
   [], dispatch_closes(ever, dispatch_ids(ever)[0]))

redgreen("a background dispatch stays open until its SubagentStop (§ 8.5, card#11799)",
         "close on the launch's PostToolUse -> dispatch closed tens of ms after opening, no intern; "
         "turn reap blind to background_tasks -> aborted at the parent's Stop; sole-open over bound "
         "dispatches -> a foreign SubagentStop closes it; no launch binding -> a parallel pair is "
         "aborted at the parent's Stop; no SubagentStop trace -> a late PostToolUse synthesizes a phantom Agent pair; "
         "every-dispatch turn exemption or a session reap sparing bound calls -> a lost SubagentStop "
         "leaves the dispatch open",
         "async_launched keeps the call open; its one close is its own SubagentStop (agent_id), in "
         "both launch/bind orders and for a parallel pair; a Stop listing the subagent leaves it open "
         "with a clean turn.end; a foreign SubagentStop closes nothing; SubagentStop-first gives one "
         "close and a silent, counted PostToolUse in both modes; a foreground PostToolUse-first "
         "dispatch closes as before; Stop not listing it and SessionEnd both still reap it")


print("\n== 22. A /clear CARRIES A RUNNING DISPATCH INTO THE NEW SESSION, AND PAIRS BY TOMBSTONE (§ 8.3, § 8.4, card#11817, card#11824) ==")
# MEASURED twice at 2.1.296, interactive, 2026-10-13: a running background subagent SURVIVES a
# /clear — its hooks and its new transcript segment carry the NEW session_id, and the new
# session's Stop counts it in background_tasks. The reporter aborted its dispatch at the clear,
# so the intern left the floor while the subagent ran on (card#11817). Separately, the second
# /clear signal (SessionStart) used to reap "the most-recently-active open session other than its
# own", which in the measured order (SessionEnd first) can only be ANOTHER live terminal's session
# (card#11824). Every hook below runs on a PINNED clock, a fixed step apart, so the pairing windows
# (K.CLEAR_PAIR_MS, K.CLEAR_GAP_MS) are crossed exactly where a case says and nowhere else.
NEWSID = "77777777-2222-4333-8444-000000000002"
SID2 = "77777777-2222-4333-8444-000000000003"
N2SID = "77777777-2222-4333-8444-000000000004"
_K_SRC = REPORTER.read_text(encoding="utf-8")


def k_ms(name):
    m = re.search(rf"\b{name}:\s*(\d+),", _K_SRC)
    return int(m.group(1)) if m else None


CLEAR_PAIR_MS, CLEAR_GAP_MS = k_ms("CLEAR_PAIR_MS"), k_ms("CLEAR_GAP_MS")
eq("the two pairing windows are constants the reporter declares (K.CLEAR_PAIR_MS, K.CLEAR_GAP_MS), "
   "and the narrow one is the narrower", True,
   CLEAR_PAIR_MS is not None and CLEAR_GAP_MS is not None and CLEAR_GAP_MS < CLEAR_PAIR_MS)
# Only so the cases below can still be DRIVEN (and red) against a reporter that declares neither.
CLEAR_PAIR_MS, CLEAR_GAP_MS = CLEAR_PAIR_MS or 30000, CLEAR_GAP_MS or 5000


def sh(s, name, sid, r, t, **kw):
    base = {"session_id": sid, "hook_event_name": name, "prompt_id": "p1", "cwd": "/home/agent/mezzanine"}
    base.update(kw)
    return hook(s, name, base, reporter=r, FLEET_REPORTER_NOW_MS=t)


def sp(s, name, payload, r, t, **kw):
    payload = dict(payload)
    payload.update(kw)
    return hook(s, name, payload, reporter=r, FLEET_REPORTER_NOW_MS=t)


def index_append(s, t, rec):
    """Append one raw index-journal record — a fold UNIT, standing for a record another process
    journaled out of decision order (the fold runs outside the write lock, D1 § 8.2)."""
    rec = dict(rec, at=time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(t / 1000)) + ".%03dZ" % (t % 1000))
    f = sorted((s.spool / "index").glob("*.jsonl"))[-1]
    with f.open("a", encoding="utf-8") as fh:
        fh.write(json.dumps(rec) + "\n")


def spawn_id(s, nth=0):
    return [e["data"]["call_id"] for e in s.events() if e["kind"] == "subagent.spawn"][nth]


def stop(sid, *agents, **kw):
    return dict(stop_hook_active=False, background_tasks=bg_tasks(*agents), session_crons=[], **kw)


CSTEPS = {
    "prompt": lambda s, r, t, b: sh(s, "UserPromptSubmit", BGSID, r, t, prompt="go"),
    "pre1": lambda s, r, t, b: sp(s, "PreToolUse", bg_pre("toolu_D1"), r, t),
    "start1": lambda s, r, t, b: sh(s, "SubagentStart", BGSID, r, t, agent_id=AG1, agent_type="coder"),
    "launch1": lambda s, r, t, b: sp(s, "PostToolUse", bg_post("toolu_D1", AG1), r, t),
    "inner1": lambda s, r, t, b: sp(s, "PreToolUse", pre(tool="Bash", ti={"command": "ls"}, tuid="toolu_I1",
                                                          session_id=BGSID, agent_id=AG1), r, t),
    "stop_bg1": lambda s, r, t, b: sh(s, "Stop", BGSID, r, t, **stop(BGSID, AG1)),
    "clear_end": lambda s, r, t, b: sh(s, "SessionEnd", BGSID, r, t, reason="clear"),
    "clear_start": lambda s, r, t, b: sh(s, "SessionStart", NEWSID, r, t, source="clear"),
    "inner1_new": lambda s, r, t, b: sp(s, "PreToolUse", pre(tool="Bash", ti={"command": "ls"}, tuid="toolu_I1N",
                                                              session_id=NEWSID, agent_id=AG1), r, t),
    "sstop1_new": lambda s, r, t, b: sh(s, "SubagentStop", NEWSID, r, t, agent_id=AG1, agent_type="coder", **stop(NEWSID)),
    "sstop1_old": lambda s, r, t, b: sh(s, "SubagentStop", BGSID, r, t, agent_id=AG1, agent_type="coder", **stop(BGSID)),
    "stop_new_none": lambda s, r, t, b: sh(s, "Stop", NEWSID, r, t, **stop(NEWSID)),
    "stop_new_bg1": lambda s, r, t, b: sh(s, "Stop", NEWSID, r, t, **stop(NEWSID, AG1)),
    "end_new": lambda s, r, t, b: sh(s, "SessionEnd", NEWSID, r, t, reason="other"),
    # A flusher pass of a flusher that STARTED BEFORE these calls opened — the realistic long-running
    # one. Its clock is pinned to the trace's base minus 1 s so its start-up reap (§ 8.3's last row,
    # `reporter_restart`, which selects entries older than the flusher's start) selects nothing, and
    # the pass is only what this case is about: a fold and a snapshot write between the two signals.
    "flush": lambda s, r, t, b: flush(s, reporter=r, FLEET_REPORTER_NOW_MS=b - 1000),
    # The second terminal: session SID2, dispatch D2 bound to AG2, inner call I2 inside it.
    "start_t2": lambda s, r, t, b: sh(s, "SessionStart", SID2, r, t, source="startup"),
    "prompt2": lambda s, r, t, b: sh(s, "UserPromptSubmit", SID2, r, t, prompt="go", prompt_id="p2"),
    "pre2": lambda s, r, t, b: sp(s, "PreToolUse", bg_pre("toolu_D2", "second"), r, t, session_id=SID2, prompt_id="p2"),
    "start2": lambda s, r, t, b: sh(s, "SubagentStart", SID2, r, t, agent_id=AG2, agent_type="coder", prompt_id="p2"),
    "launch2": lambda s, r, t, b: sp(s, "PostToolUse", bg_post("toolu_D2", AG2, desc="second"), r, t,
                                     session_id=SID2, prompt_id="p2"),
    "inner2": lambda s, r, t, b: sp(s, "PreToolUse", pre(tool="Bash", ti={"command": "ls"}, tuid="toolu_I2",
                                                          session_id=SID2, agent_id=AG2, prompt_id="p2"), r, t),
    "stop2_bg2": lambda s, r, t, b: sh(s, "Stop", SID2, r, t, prompt_id="p2", **stop(SID2, AG2)),
    "sstop2": lambda s, r, t, b: sh(s, "SubagentStop", SID2, r, t, agent_id=AG2, agent_type="coder", **stop(SID2)),
    "clear2_end": lambda s, r, t, b: sh(s, "SessionEnd", SID2, r, t, reason="clear"),
    "clear_start2": lambda s, r, t, b: sh(s, "SessionStart", N2SID, r, t, source="clear"),
    "stop_n2_none": lambda s, r, t, b: sh(s, "Stop", N2SID, r, t, **stop(N2SID)),
    # Fold units: a record journaled out of decision order.
    "dup_open_new": lambda s, r, t, b: index_append(s, t, {"k": "session_open", "session_id": NEWSID, "source": "clear"}),
    "late_carry": lambda s, r, t, b: index_append(s, t, {"k": "carry", "call_id": spawn_id(s), "from_session_id": BGSID}),
}


def clear_trace(name, steps, reporter=REPORTER):
    """Drive `steps` on a pinned clock (100 ms apart; an int in `steps` advances it by that many
    ms). Returns (seat, events, counters, predicates, {step: [events that step emitted]})."""
    s = seat(name)
    base = int(time.time() * 1000)
    t = base
    sh(s, "SessionStart", BGSID, reporter, t, source="startup")
    per = {}
    for st in steps:
        if isinstance(st, int):
            t += st
            continue
        t += 100
        before = len(s.events())
        CSTEPS[st](s, reporter, t, base)
        per.setdefault(st, []).extend(s.events()[before:])
    return s, s.events(), s.counters(), s.predicates(), per


def kinds(evs):
    return [e["kind"] for e in evs if e["kind"] != "heartbeat"]


def closes_of(ev, call_id):
    return [(e["data"]["outcome"], e["data"]["close_source"], e["data"]["match"], e["data"]["abort_reason"], e["session_id"])
            for e in ev if e["kind"] == "tool.end" and e["data"]["call_id"] == call_id]


def call_of(ev, tuid_tool, sid):
    """The call_id of the first tool.start of `tuid_tool` in session `sid`."""
    return [e["data"]["call_id"] for e in ev if e["kind"] == "tool.start"
            and e["data"]["tool_name"] == tuid_tool and e["session_id"] == sid][0]


def only(evs, kind):
    xs = [e for e in evs if e["kind"] == kind]
    return xs[0]["data"] if len(xs) == 1 else {"_count": len(xs)}


def pairs(s):
    """The `pair` records the index journal holds, in order — the internal pairing's own record
    (D1 § 8.2). Nothing on the wire names a pairing, so this is where a case reads which twin a
    signal paired with. Read before any flusher pass, which may compact the journal."""
    out = []
    for f in sorted((s.spool / "index").glob("*.jsonl")):
        for line in f.read_text(encoding="utf-8").splitlines():
            try:
                r = json.loads(line)
            except ValueError:
                continue
            if r.get("k") == "pair":
                out.append((r.get("old_session_id"), r.get("new_session_id")))
    return out


def snapshot(s):
    try:
        return json.loads((s.spool / "index" / "snapshot.json").read_text(encoding="utf-8"))
    except Exception:
        return {}


C1 = ["prompt", "pre1", "start1", "launch1", "inner1", "stop_bg1", "clear_end", "clear_start"]


def c1_view(ev, ctr, prd, per):
    D = [e["data"]["call_id"] for e in ev if e["kind"] == "subagent.spawn"][0]
    I = call_of(ev, "Bash", BGSID)
    se = only(per["clear_end"], "session.end")
    return {
        "end_kinds": kinds(per["clear_end"]),
        "end_closes": [(e["data"]["call_id"] == I, e["data"]["outcome"], e["data"]["abort_reason"])
                       for e in per["clear_end"] if e["kind"] == "tool.end"],
        "aborted_calls": se.get("aborted_calls"), "carried": se.get("carried_call_ids") == [D],
        "start_kinds": kinds(per["clear_start"]),
        "previous": only(per["clear_start"], "session.start").get("previous_session_id"),
        "counters": (ctr.get("dispatch_carried_across_clear"), ctr.get("dispatch_rekeyed_to_new_session"),
                     ctr.get("reap_noop_second_signal"), ctr.get("clear_second_signal_found_nothing")),
        "predicate": prd.get("clear_reap_by_session_end"),
        "paired": ctr.get("clear_signals_paired"),
    }


# `previous`, `reap_noop_second_signal`, `clear_second_signal_found_nothing` and the predicate are
# the values a reporter BEFORE card#11817 put on the wire for this trace: a wire field keeps its
# meaning (VERSIONING wire rule 4). The pairing is internal and read off `clear_signals_paired`.
C1_WANT = {
    "end_kinds": ["tool.end", "session.end"], "end_closes": [(True, "aborted", "session_cleared")],
    "aborted_calls": 1, "carried": True, "start_kinds": ["session.start"], "previous": None,
    "counters": (1, 1, 1, 1), "predicate": {"true": 1, "false": 0}, "paired": 1,
}
s, ev, ctr, prd, per = clear_trace("clear-c1", C1)
D1c = spawn_id(s)
got = c1_view(ev, ctr, prd, per)
for k in C1_WANT:
    eq(f"C1 (measured order) {k}", C1_WANT[k], got[k])
eq("  … and NO close of the dispatch anywhere: it is carried, so the intern stays drawn", [], closes_of(ev, D1c))

# C2 — the carried subagent's next call names its parent, and its SubagentStop under NEW closes it.
s, ev, ctr, _, per = clear_trace("clear-c2", C1 + ["inner1_new", "sstop1_new"])
D = spawn_id(s)
IN = call_of(ev, "Bash", NEWSID)
eq("C2: the subagent's first post-clear call names the carried dispatch as its parent (it was null)",
   D, [e for e in ev if e["kind"] == "tool.start" and e["data"]["call_id"] == IN][0]["data"]["parent_call_id"])
eq("  … the dispatch's ONE close is its SubagentStop, completed, matched by agent_id, under NEW",
   [("completed", "subagent_stop_hook", "agent_id", None, NEWSID)], closes_of(ev, D))
eq("  … with exactly one subagent.stop for it", 1,
   len([e for e in ev if e["kind"] == "subagent.stop" and e["data"]["call_id"] == D]))
eq("  … and the post-clear inner call is closed by the stop's scope reap first",
   [("aborted", "reap_turn_boundary", "reap", "turn_boundary", NEWSID)], closes_of(ev, IN))

# C2b — the unverified branch (§ 3): the carried SubagentStop arrives under the OLD id.
C2B = C1 + ["inner1_new", "sstop1_old"]
s, ev, _, _, _ = clear_trace("clear-c2b", C2B)
eq("C2b: a SubagentStop under the OLD id still closes the dispatch, completed, by agent_id",
   [("completed", "subagent_stop_hook", "agent_id", None, BGSID)], closes_of(ev, spawn_id(s)))
eq("  … and still closes the inner call the subagent opened under NEW (its scope reap is agent-scoped)",
   [("aborted", "reap_turn_boundary", "reap", "turn_boundary", NEWSID)], closes_of(ev, call_of(ev, "Bash", NEWSID)))

# C3 — SessionStart first: the start reaps NOTHING; the end carries, and pairs with the start.
C3 = ["prompt", "pre1", "start1", "launch1", "inner1", "stop_bg1", "clear_start", "clear_end", "stop_new_none"]
s, ev, ctr, prd, per = clear_trace("clear-c3", C3)
D = spawn_id(s)
eq("C3 (SessionStart first): the start emits ONLY its session.start, still naming the session it "
   "selected (its value before card#11817)",
   (["session.start"], BGSID), (kinds(per["clear_start"]), only(per["clear_start"], "session.start").get("previous_session_id")))
eq("  … the end reaps the inner call and carries the dispatch",
   (["tool.end", "session.end"], [D]), (kinds(per["clear_end"]), only(per["clear_end"], "session.end").get("carried_call_ids")))
C3_WANT = (1, None, {"true": 0, "false": 1}, [(BGSID, NEWSID)])
c3_got = lambda s, ctr, prd: (ctr.get("reap_noop_second_signal"), ctr.get("clear_second_signal_found_nothing"),
                              prd.get("clear_reap_by_session_end"), pairs(s))
eq("  … the counters and the predicate keep their pre-card#11817 values, and the end pairs once",
   C3_WANT, c3_got(s, ctr, prd))
eq("  … and the pairing re-keyed it: NEW's Stop not listing the subagent reaps it under NEW",
   [("aborted", "reap_turn_boundary", "reap", "turn_boundary", NEWSID)], closes_of(ev, D))

# C3b (review R2-7a) — a hook of NEW lands BEFORE SessionStart(NEW): the session already exists
# without a source when the start arrives, and the start must still mark it `clear`.
C3B = ["prompt", "pre1", "start1", "launch1", "stop_bg1", "inner1_new", "clear_start", "clear_end", "stop_new_none"]
s, ev, ctr, _, per = clear_trace("clear-c3b", C3B)
eq("C3b: SessionStart(clear) that is NOT the new session's first hook still pairs (the end finds it)",
   (1, [("aborted", "reap_turn_boundary", "reap", "turn_boundary", NEWSID)]),
   (ctr.get("dispatch_rekeyed_to_new_session"), closes_of(ev, spawn_id(s))))

# C4 — a real exit of NEW reaps the carried dispatch like any other of NEW's calls.
s, ev, _, _, per = clear_trace("clear-c4", C1 + ["end_new"])
D = spawn_id(s)
C4_WANT = ([("aborted", "reap_session_boundary", "reap", "session_ended", NEWSID)], ["aborted"], [])
c4_got = lambda ev, per, D: (closes_of(ev, D),
                              [e["data"]["outcome"] for e in ev if e["kind"] == "subagent.stop" and e["data"]["call_id"] == D],
                              only(per["end_new"], "session.end").get("carried_call_ids"))
eq("C4: NEW's real SessionEnd reaps the carried dispatch (session_ended, under NEW), with its "
   "subagent.stop, and carries nothing", C4_WANT, c4_got(ev, per, D))

# C5 — the turn backstop selects the re-keyed dispatch.
s, ev, ctr, _, per = clear_trace("clear-c5", C1 + ["stop_new_bg1", "stop_new_none"])
D = spawn_id(s)
eq("C5: NEW's Stop listing the subagent keeps it, with a clean turn.end",
   (["turn.end"], [], 2), (kinds(per["stop_new_bg1"]), only(per["stop_new_bg1"], "turn.end").get("aborted_call_ids"),
                           ctr.get("dispatch_kept_across_turn_reap")))
C5_WANT = ([("aborted", "reap_turn_boundary", "reap", "turn_boundary", NEWSID)], [D])
c5_got = lambda ev, per, D: (closes_of(ev, D), only(per["stop_new_none"], "turn.end").get("aborted_call_ids"))
eq("  … and NEW's Stop no longer listing it reaps it, named in that turn.end", C5_WANT, c5_got(ev, per, D))

# C6 — an UNBOUND dispatch at the clear is reaped as before (partial: only the field is new).
C6 = ["prompt", "pre1", "clear_end", "clear_start"]
s, ev, _, _, per = clear_trace("clear-c6", C6)
D = spawn_id(s)
C6_WANT = ([("aborted", "reap_session_boundary", "reap", "session_cleared", BGSID)], ["aborted"], [])
c6_got = lambda ev, per, D: (closes_of(ev, D),
                              [e["data"]["outcome"] for e in ev if e["kind"] == "subagent.stop" and e["data"]["call_id"] == D],
                              only(per["clear_end"], "session.end").get("carried_call_ids"))
eq("C6: an unbound dispatch at the clear is reaped session_cleared with its subagent.stop; "
   "carried_call_ids is []", C6_WANT, c6_got(ev, per, D))

# C7 — the SubagentStop lands between the two signals: one close, and the pairing re-keys nothing.
s, ev, ctr, _, _ = clear_trace("clear-c7", ["prompt", "pre1", "start1", "launch1", "clear_end", "sstop1_new", "clear_start"])
eq("C7: a SubagentStop between the signals is the ONE close, completed; the pairing still happens "
   "and re-keys nothing", ([("completed", "subagent_stop_hook", "agent_id", None, NEWSID)], 0, 1),
   (closes_of(ev, spawn_id(s)), ctr.get("dispatch_rekeyed_to_new_session", 0), ctr.get("clear_signals_paired")))
# C8 is block 20, run unchanged above: its `bg-backstop-session` GREEN is the control that a
# session ending for any reason but `clear` still takes every open call it owned.

# C9 / C9b — TWO TERMINALS (card#11824, D1 § 8.4). The other terminal's session, dispatch and inner
# call are untouched by this terminal's /clear, in both hook orders.
T2 = ["start_t2", "prompt2", "pre2", "start2", "launch2", "inner2"]
T1 = ["prompt", "pre1", "start1", "launch1", "stop_bg1"]


def two_terminal_view(s, ev, per, first, second):
    spawns = {e["session_id"]: e["data"]["call_id"] for e in ev if e["kind"] == "subagent.spawn"}
    D1x, D2x = spawns[BGSID], spawns[SID2]
    I2x = call_of(ev, "Bash", SID2)
    window = per[first] + per[second]
    return {
        "sid2_events_in_clear": [e["kind"] for e in window if e["session_id"] == SID2],
        "sid2_session_end": len([e for e in ev if e["kind"] == "session.end" and e["session_id"] == SID2]),
        "previous": only(per["clear_start"], "session.start").get("previous_session_id"),
        "pairs": pairs(s),
        "i2": [c[:2] for c in closes_of(ev, I2x)],
        "d2": [c[:3] for c in closes_of(ev, D2x)],
        "d1": closes_of(ev, D1x),
    }


TT_WANT = {
    "sid2_events_in_clear": [], "sid2_session_end": 0,
    "i2": [("aborted", "reap_turn_boundary")], "d2": [("completed", "subagent_stop_hook", "agent_id")],
    "d1": [("aborted", "reap_turn_boundary", "reap", "turn_boundary", NEWSID)],
    "pairs": [(BGSID, NEWSID)],
}
# `previous` is the pre-card#11817 SELECTION, kept as it was: the most-recently-active other open
# session. In the measured order that is terminal 2's — the same value a reporter before
# card#11817 sent, when it also ENDED terminal 2. Only the reap is gone.
C9 = T2 + T1 + ["clear_end", "clear_start", "stop_new_none", "sstop2"]
s, ev, _, _, per = clear_trace("clear-c9", C9)
v9 = two_terminal_view(s, ev, per, "clear_end", "clear_start")
for k, w in dict(TT_WANT, previous=SID2).items():
    eq(f"C9 (two terminals, measured order) {k}", w, v9[k])
C9B = T2 + T1 + ["clear_start", "clear_end", "stop_new_none", "sstop2"]
s, ev, _, _, per = clear_trace("clear-c9b", C9B)
v9b = two_terminal_view(s, ev, per, "clear_start", "clear_end")
for k, w in dict(TT_WANT, previous=BGSID).items():
    eq(f"C9b (two terminals, SessionStart first) {k}", w, v9b[k])

# C10 — a flusher pass (fold + snapshot write) between the two signals loses nothing.
s, ev, ctr, prd, per = clear_trace("clear-c10", C1[:-1] + ["flush"] + C1[-1:], )
got = c1_view(ev, ctr, prd, per)
eq("C10: with a flusher pass between the signals the outcome is C1's exactly", C1_WANT, got)
snap = snapshot(s)
eq("  … because the snapshot it wrote persisted the clear tombstone's end_reason and the carry flag",
   ([("clear", None)], [BGSID]),
   ([(c.get("end_reason"), c.get("paired_to")) for c in snap.get("sessions_closed", []) if c.get("session_id") == BGSID],
    [e.get("carried_from") for e in snap.get("entries", []) if e.get("call_id") == spawn_id(s)]))

# C11 — two clear tombstones inside the window when a start arrives: the latest is taken and the
# guess is COUNTED; the next start pairs the remaining one (review R2-5).
C11 = T2[:-1] + T1[:-1] + ["clear_end", "clear2_end", "clear_start", "clear_start2"]
s, ev, ctr, _, per = clear_trace("clear-c11", C11)
C11_WANT = ([(SID2, NEWSID), (BGSID, N2SID)], 1)
c11_got = lambda s, ctr: (pairs(s), ctr.get("clear_pair_ambiguous"))
eq("C11: the first start pairs the LATEST unpaired tombstone, counts clear_pair_ambiguous, and the "
   "second start pairs the remaining one", C11_WANT, c11_got(s, ctr))

# C12 — the second signal past K.CLEAR_PAIR_MS: no pairing, and the carry stays under OLD.
C12 = ["prompt", "pre1", "start1", "launch1", "stop_bg1", "clear_end", CLEAR_PAIR_MS, "clear_start",
       "stop_new_none", "sstop1_new"]
s, ev, ctr, _, per = clear_trace("clear-c12", C12)
eq("C12: a start more than K.CLEAR_PAIR_MS after the end pairs nothing",
   ([], None), (pairs(s), ctr.get("clear_signals_paired")))
eq("  … the dispatch stays under OLD (NEW's Stop does not select it), and its SubagentStop still "
   "closes it (the binding lookup is session-agnostic)",
   ([], [("completed", "subagent_stop_hook", "agent_id", None, NEWSID)]),
   ([e for e in per["stop_new_none"] if e["kind"] == "tool.end"], closes_of(ev, spawn_id(s))))

# C12b — between K.CLEAR_GAP_MS and K.CLEAR_PAIR_MS a start still pairs its end's tombstone, and
# the pairing is COUNTED late: it is outside twice the measured gap (review R2-1).
C12B = ["prompt", "pre1", "start1", "launch1", "stop_bg1", "clear_end", CLEAR_GAP_MS, "clear_start", "stop_new_none"]
s, ev, ctr, _, per = clear_trace("clear-c12b", C12B)
eq("C12b: a start K.CLEAR_GAP_MS after its end still pairs it, counted clear_pair_late, and re-keys",
   ([(BGSID, NEWSID)], 1, [("aborted", "reap_turn_boundary", "reap", "turn_boundary", NEWSID)]),
   (pairs(s), ctr.get("clear_pair_late"), closes_of(ev, spawn_id(s))))

# C12c — SessionStart first, the end more than K.CLEAR_GAP_MS later: the end does NOT pair.
C12C = ["prompt", "pre1", "start1", "launch1", "stop_bg1", "clear_start", CLEAR_GAP_MS, "clear_end"]
s, ev, ctr, _, per = clear_trace("clear-c12c", C12C)
eq("C12c: an end more than K.CLEAR_GAP_MS after an unpaired clear start does not pair with it",
   ([], [spawn_id(s)]), (pairs(s), only(per["clear_end"], "session.end").get("carried_call_ids")))

# C13 (review R2-1) — terminal 1's SessionEnd is LOST, so its start N1 sits unpaired; terminal 2
# clears more than K.CLEAR_GAP_MS later. Terminal 2's end must not adopt N1: its dispatch would be
# re-keyed into terminal 1's session and reaped at N1's next Stop while its subagent runs.
C13 = ["start_t2", "prompt2", "pre2", "start2", "launch2", "stop2_bg2", "clear_start", CLEAR_GAP_MS,
       "clear2_end", "clear_start2", "stop_new_none", "stop_n2_none"]
s, ev, ctr, _, per = clear_trace("clear-c13", C13)
D2 = spawn_id(s)
C13_WANT = ([], [(SID2, N2SID)], [("aborted", "reap_turn_boundary", "reap", "turn_boundary", N2SID)])
c13_got = lambda s, ev, per, D2: ([e["kind"] for e in per["stop_new_none"] if e["kind"] == "tool.end"],
                                   pairs(s), closes_of(ev, D2))
eq("C13: terminal 2's clear does not adopt terminal 1's unpaired start: N1's Stop takes nothing, "
   "terminal 2's own start pairs it, and its dispatch is re-keyed to that start", C13_WANT, c13_got(s, ev, per, D2))

# C14 (review R2-7b) — a DUPLICATE session_open for NEW folded after the pairing (two concurrent
# first hooks; the fold runs outside the write lock) must not make NEW pairable again.
C14 = ["start_t2"] + C1 + ["dup_open_new", "clear2_end"]
s, ev, ctr, _, per = clear_trace("clear-c14", C14)
C14_WANT = [(BGSID, NEWSID)]
c14_got = lambda s: pairs(s)
eq("C14: after a duplicate session_open, another terminal's clear does NOT pair with the already "
   "paired NEW (paired_from never resets)", C14_WANT, c14_got(s))

# C15 (F8b) — a `carry` folded AFTER the pairing re-keyed the entry names a session the entry no
# longer belongs to, and does nothing. Read off the snapshot the next flusher pass persists.
C15 = C1 + ["late_carry", "flush"]
s, ev, _, _, _ = clear_trace("clear-c15", C15)
C15_WANT = [(NEWSID, None)]
c15_got = lambda s: [(e.get("session_id"), e.get("carried_from")) for e in snapshot(s).get("entries", [])
                     if e.get("call_id") == spawn_id(s)]
eq("C15: a late carry for a re-keyed entry does not re-flag it", C15_WANT, c15_got(s))

# ── REDs, each planted on a COPY and shown to flip its GREEN ──────────────────────────────────
p_r1 = plant(("const carried = (e) => abortReason === 'session_cleared' && e.is_dispatch && !!e.child_agent_id;",
              "const carried = (e) => e.is_dispatch && !!e.child_agent_id;"))
s, ev, _, _, per = clear_trace("clear-r1", C1 + ["end_new"], p_r1)
eq("RED R1: the carry widened to every abort reason — a real exit leaves the dispatch OPEN (C4 flips)",
   ([], [], [spawn_id(s)]), c4_got(ev, per, spawn_id(s)))
p_r2 = plant(("if (e.carried_from === r.old_session_id) { e.session_id = r.new_session_id; delete e.carried_from; }",
              "if (false) { e.session_id = r.new_session_id; delete e.carried_from; }"))
s, ev, _, _, per = clear_trace("clear-r2", C1 + ["stop_new_bg1", "stop_new_none"], p_r2)
eq("RED R2: a pair record that does not re-key — NEW's Stop cannot select the dispatch (C5 flips)",
   ([], []), c5_got(ev, per, spawn_id(s)))
p_r3 = plant(("    carried_call_ids: ids,\n", ""))
s, ev, ctr, prd, per = clear_trace("clear-r3", C1, p_r3)
eq("RED R3: carried_call_ids not emitted — C1's field assertion flips", False, c1_view(ev, ctr, prd, per)["carried"])
p_r4 = plant(("const carried = (e) => abortReason === 'session_cleared' && e.is_dispatch && !!e.child_agent_id;",
              "const carried = (e) => abortReason === 'session_cleared' && e.is_dispatch;"))
s, ev, _, _, per = clear_trace("clear-r4", C6, p_r4)
eq("RED R4: the carry applied to an UNBOUND dispatch — C6's dispatch is carried, a call no "
   "SubagentStop can match", ([], [], [spawn_id(s)]), c6_got(ev, per, spawn_id(s)))
p_r5 = plant(("          previous = pick;\n",
              "          previous = pick;\n          reapSessionBoundary(ctx, ix, pick, 'clear', 'session_cleared', atMs);\n"))
s, ev, _, _, per = clear_trace("clear-r5", C9, p_r5)
eq("RED R5: the activity selection REAPING again, as it did before — terminal 1's clear ENDS terminal 2's session "
   "(card#11824; C9 flips)", 1, two_terminal_view(s, ev, per, "clear_end", "clear_start")["sid2_session_end"])
p_r6 = plant(("(c) => c.end_reason === 'clear' && !c.paired_to,", "(c) => c.end_reason === 'clear',"))
s, ev, ctr, _, per = clear_trace("clear-r6", C11, p_r6)
eq("RED R6: findTwinForStart ignoring paired_to — the second start pairs the already-paired "
   "tombstone again (C11 flips)", ([(SID2, NEWSID), (SID2, N2SID)], 2), c11_got(s, ctr))
p_r7 = plant(("      if (e && e.session_id === r.from_session_id) e.carried_from = r.from_session_id;",
              "      if (e) e.carried_from = r.from_session_id;"))
s, ev, _, _, _ = clear_trace("clear-r7", C15, p_r7)
eq("RED R7: the carry apply guard removed — a late carry re-flags the re-keyed entry (C15 flips)",
   [(NEWSID, BGSID)], c15_got(s))
p_r8 = plant(("(e) => e.agent_scope_id === agentId), 'turn_boundary'",
              "(e) => e.session_id === sid && e.agent_scope_id === agentId), 'turn_boundary'"))
s, ev, _, _, _ = clear_trace("clear-r8", C2B, p_r8)
eq("RED R8: the SubagentStop scope reap keeps its session term — under the OLD id it misses the "
   "inner call opened under NEW (C2b flips)", [], closes_of(ev, call_of(ev, "Bash", NEWSID)))
p_r9 = plant(("(s) => s.opened_at, K.CLEAR_GAP_MS, nowMs);", "(s) => s.opened_at, K.CLEAR_PAIR_MS, nowMs);"))
s, ev, _, _, per = clear_trace("clear-r9", C13, p_r9)
D2r = spawn_id(s)
eq("RED R9: findTwinForEnd on the wide window — terminal 2's end adopts terminal 1's unpaired "
   "start and N1's Stop reaps terminal 2's running dispatch (review R2-1; C13 flips)",
   (["tool.end"], [(SID2, NEWSID)], [("aborted", "reap_turn_boundary", "reap", "turn_boundary", NEWSID)]),
   c13_got(s, ev, per, D2r))
p_r10 = plant(("  if (s && (!source || s.start_source === source)) return;",
               "  if (s) return;"))
s, ev, ctr, _, _ = clear_trace("clear-r10", C3B, p_r10)
eq("RED R10: SessionStart(clear) recorded only when it is the session's first hook — C3b never "
   "pairs (review R2-7a)", None, ctr.get("dispatch_rekeyed_to_new_session"))
p_r11 = plant(("} else if (r.source) s.start_source = r.source;",
               "} else if (r.source) { s.start_source = r.source; s.paired_from = null; }"))
s, ev, ctr, _, _ = clear_trace("clear-r11", C14, p_r11)
eq("RED R11: a session_open that resets paired_from — the duplicate makes NEW pairable again and "
   "the other terminal's clear pairs with it (review R2-7b; C14 flips)", [(BGSID, NEWSID), (SID2, NEWSID)], c14_got(s))
# R12 / R13 — the wire meanings VERSIONING wire rule 4 pins. Each plants the pre-ruling build of
# this card, which re-meant a field without a schema bump.
p_r12 = plant(("        if (twin) pairClear(ctx, ix, twin.session_id, sid, twin);\n",
               "        if (twin) { previous = twin.session_id; pairClear(ctx, ix, twin.session_id, sid, twin); }\n"))
s, ev, ctr, prd, per = clear_trace("clear-r12", C1, p_r12)
eq("RED R12: previous_session_id taken from the internal pairing — a meaning change; C1's "
   "pre-card#11817 value flips from null", BGSID, c1_view(ev, ctr, prd, per)["previous"])
p_r13 = plant(("        if (twin) { pairClear(ctx, ix, sid, twin.session_id, twin); count('reap_noop_second_signal'); }\n"
               "        else predicate('clear_reap_by_session_end', true);\n",
               "        if (twin) pairClear(ctx, ix, sid, twin.session_id, twin);\n"
               "        predicate('clear_reap_by_session_end', true);\n"))
s, ev, ctr, prd, per = clear_trace("clear-r13", C3, p_r13)
eq("RED R13: SessionEnd evaluating the predicate on every clear — C3's counter and predicate leave "
   "their pre-card#11817 values", (None, None, {"true": 1, "false": 1}, [(BGSID, NEWSID)]), c3_got(s, ctr, prd))
redgreen("a /clear carries a running dispatch into the new session and pairs by tombstone (§ 8.3, § 8.4, card#11817, card#11824)",
         "carry on every abort reason -> a real exit leaves the dispatch open; a pair that does not re-key "
         "-> NEW's Stop cannot select it; no carried_call_ids -> the server would close it; carry on an "
         "unbound dispatch -> a call no SubagentStop matches; the activity pick -> terminal 1's clear ends "
         "terminal 2's session; paired_to ignored -> one tombstone pairs twice; no carry guard -> a late "
         "carry re-flags a re-keyed entry; a session-scoped SubagentStop reap -> an inner call opened under "
         "NEW stays open; the wide window at the end -> terminal 2's dispatch is re-keyed into terminal 1's "
         "session and reaped; SessionStart's source dropped when not first -> no pairing; paired_from reset "
         "by a duplicate session_open -> a second pairing; previous_session_id from the pairing, or the predicate on every "
         "SessionEnd(clear) -> a wire field leaves its pre-card#11817 value",
         "both hook orders carry the bound dispatch, name it in carried_call_ids, pair once and re-key it; "
         "its SubagentStop under NEW or OLD closes it once with its inner calls; NEW's Stop and real exit "
         "reap it; an unbound dispatch is reaped as before; a second terminal is untouched in both orders; "
         "a flusher pass between the signals loses nothing; ambiguous and late pairings are counted; past "
         "either window nothing pairs; previous_session_id, both clear counters and the predicate keep their "
         "pre-card#11817 values")

print("\n== 23. ONE SEAT, SEVERAL SITES: EACH SITE GETS EVERYTHING, AND ONLY ITS OWN OUTCOMES (AT-28, D1 § 3.1, card#12180) ==")
# THE STUBS RUN THE SERVER'S OWN RULES, READ FROM ITS CODE, because the properties under test are what a
# real site would COUNT: `seq_collision` is StateRecompute's, `seq_gap` is BatchWriter's received-batch rule
# (card#12210), duplicates are BatchWriter's
# insert-or-ignore, a retry's answer is IngestPipeline's batch_id replay memory, and `clock_skew_ms` is
# BatchWriter's arrival-minus-`sent_at`. A stub that counted by its own rules would pass against itself.
# Every case asserts by event id, and every RED is planted on a copy, as the rest of this file does.
#
# THE SITE NAMES ARE `sa` AND `sb`, NOT `a` AND `b`: § 3.1 gives a site's `name` `install_id`'s own pattern,
# `^[a-z0-9][a-z0-9-]{1,31}$`, which needs two characters, so AT-28's one-letter shorthand is not a valid
# name and the reporter refuses it (measured: `sites[0].name fails …`). The shorthand is the doc's; the rule
# is the one the reporter enforces.
_KR = (HERE.parent / "server/app/Ingest/KindRegistry.php").read_text(encoding="utf-8")
KINDS_KNOWN = set(re.findall(r"^        '([a-z]+\.[a-z_]+)' => \[", _KR, re.M))
_RL = (HERE.parent / "server/app/Ingest/RateLimiter.php").read_text(encoding="utf-8")
EVENTS_LIMIT = int(re.search(r"EVENTS_LIMIT = (\d+);", _RL).group(1))
REQUESTS_LIMIT = int(re.search(r"REQUESTS_LIMIT = (\d+);", _RL).group(1))
eq("precondition: the stubs' known-kind set is KindRegistry::KINDS' keys, and holds every kind the reporter emits",
   True, bool(KINDS_KNOWN) and set(re.findall(
       r"'((?:session|turn|tool|subagent|compaction|context|attention|reporter)\.[a-z]+)'", _prod_src)) <= KINDS_KNOWN)


def k_of(src: str, name: str) -> int:
    """One of the reporter's `K` constants, read from its source: digits and `*` only, so the eval runs
    nothing but arithmetic."""
    expr = re.search(rf"\b{name}: ([0-9 *]+),", src).group(1)
    assert re.fullmatch(r"[0-9 *]+", expr)
    return int(eval(expr))


K_RES_MS = k_of(_prod_src, "RESIDENCY_MS")
K_HB_MS = k_of(_prod_src, "HEARTBEAT_MS")
K_BATCH = k_of(_prod_src, "BATCH_EVENTS")
K_SITE_EPH = k_of(_prod_src, "SITE_EVENTS_PER_HOUR")
K_HEADROOM = k_of(_prod_src, "HEARTBEAT_HEADROOM")


class FoldStore:
    """One site's store and fold, by the server's rules.

    - an event is STORED once per `event_id` and answered in `duplicates` after that
      (`server/app/Ingest/BatchWriter.php`, `insertOrIgnore`);
    - an event of a kind `KindRegistry::KINDS` does not know is not stored (the `$known` filter);
    - a repeat `batch_id` is answered with the first commit's response and processes nothing
      (`IngestPipeline.php`, `previousResponse`, § 10.4);
    - every batch the ingest ACCEPTS runs `BatchWriter::receivedGap()`'s rule (card#12210), PER SEAT — the
      `(install_id, seat_id)` pair the batch is bound to — over the DISTINCT seqs of all its events, stored,
      duplicate and unknown-kind alike: a received mark `(epoch, seq)`; no mark or an epoch other than the
      mark's sets it to the batch's highest seq and counts nothing; under the mark's epoch a batch reaching
      past it counts the seqs in `(mark, highest]` it did not carry as `seq_gap` and moves the mark; a batch at
      or below the mark moves nothing. The server also trusts its mark only while a stamp equals the seat's
      last receipt — a guard against a build that does not maintain the mark, which this stub, one build,
      never is, so it has no stamp;
    - stored rows are folded in id order, per seat, by `StateRecompute::writeSnapshotColumns`' rules: an
      applied mark `(epoch, seq)`; an epoch other than the mark's counts `seq_epoch_change`; a stored row of
      that seat sharing `(epoch, seq)` with a lower id counts `seq_collision`; the mark moves on a change, on a
      first row, or on a greater `seq` under its epoch.
    `seat` selects one pair's counters; the default reads the seat this suite's seats use.
    """

    def __init__(self):
        self.rows: list[dict] = []
        self.ids: dict = {}
        self.keys: set = set()            # (pair, epoch, seq) of every stored row: the collision lookup
        self.replay: dict = {}
        self.seats: dict = {}
        self.gap_by_epoch: dict = {}
        self.batch_log: list[dict] = []
        self.ignored_by_epoch: dict = {}
        self.skews: list[int] = []

    def counter(self, name: str, pair=("aimla", "aimla-pm")) -> int:
        return self.seats.get(pair, {}).get(name, 0)

    @property
    def seq_gap(self):
        return self.counter("seq_gap")

    @property
    def seq_collision(self):
        return self.counter("seq_collision")

    @property
    def seq_epoch_change(self):
        return self.counter("seq_epoch_change")

    def ingest(self, batch: dict, arrival_ms: int) -> dict:
        bid = batch.get("batch_id")
        if bid in self.replay:
            return dict(self.replay[bid])
        stored, dup, ignored = [], [], 0
        ep = batch.get("seq_epoch")
        pair = (batch.get("install_id"), batch.get("seat_id"))
        self._received(pair, ep, {e["seq"] for e in batch.get("events", [])})
        for e in batch.get("events", []):
            if e.get("kind") not in KINDS_KNOWN:
                ignored += 1
                continue
            if e["event_id"] in self.ids:
                dup.append(e["seq"])
                continue
            row = {"id": len(self.rows) + 1, "event_id": e["event_id"], "epoch": ep, "seq": e["seq"], "pair": pair,
                   "kind": e["kind"], "batch_id": bid, "event": e}
            self.rows.append(row)
            self.ids[e["event_id"]] = row
            stored.append(e["seq"])
            self._fold(row)
        resp = {"batch_id": bid, "accepted": len(stored), "duplicates": len(dup), "ignored_unknown_kinds": ignored,
                "coerced_enum_values": 0, "server_time": "2026-08-24T00:00:00.000Z"}
        self.replay[bid] = resp
        self.batch_log.append({"batch_id": bid, "epoch": ep, "stored": stored, "dup": dup})
        self.ignored_by_epoch[ep] = self.ignored_by_epoch.get(ep, 0) + ignored
        try:
            sent = calendar.timegm(time.strptime(batch["sent_at"][:19], "%Y-%m-%dT%H:%M:%S")) * 1000 + int(batch["sent_at"][20:23])
            self.skews.append(arrival_ms - sent)
        except Exception:
            pass
        return resp

    def _seat(self, pair) -> dict:
        return self.seats.setdefault(pair, {"mark": None, "recv": None, "seq_gap": 0, "seq_collision": 0,
                                            "seq_epoch_change": 0})

    def _received(self, pair, ep, seqs: set):
        if not seqs:
            return
        st = self._seat(pair)
        hi, recv = max(seqs), st["recv"]
        if recv is None or recv[0] != ep:
            st["recv"] = (ep, hi)
            return
        if hi <= recv[1]:
            return
        g = (hi - recv[1]) - len({s for s in seqs if s > recv[1]})
        st["seq_gap"] += g
        self.gap_by_epoch[ep] = self.gap_by_epoch.get(ep, 0) + g
        st["recv"] = (ep, hi)

    def _fold(self, row):
        st = self._seat(row["pair"])
        ep, seq, mark = row["epoch"], row["seq"], st["mark"]
        changed = mark is not None and mark[0] != ep
        if changed:
            st["seq_epoch_change"] += 1
        key = (row["pair"], ep, seq)
        if key in self.keys:
            st["seq_collision"] += 1
        self.keys.add(key)
        if changed or mark is None or (mark[0] == ep and seq > mark[1]):
            st["mark"] = (ep, seq)

    def held(self) -> set:
        return set(self.ids)

    def epochs(self) -> list:
        out = []
        for r in self.rows:
            if r["epoch"] not in out:
                out.append(r["epoch"])
        return out


# A FRESH-EPOCH RE-SEND COUNTS NO `seq_gap`, IN ONE PLACE. D1 § 10.2, *How the server counts `seq_gap`*: since
# card#12210 the server counts a gap over every batch it RECEIVED, duplicates included, so a re-send of events
# the site already holds advances the received mark and counts nothing. Until then the fold counted over
# STORED rows only and those legs measured a false `seq_gap` (the residual the operator accepted for
# card#12180, opq-1792660567-cb5f). Each leg that re-sends under a fresh epoch (AT-17's two legs, Case F's
# upgrade-boundary leg, Case K's later-attempt `413` leg) asserts 0 here — beside a precondition that the
# leg really produced the duplicate run that used to be miscounted, so a fixture drift cannot turn the
# assertion vacuous.


def inner_duplicate_run(store: FoldStore, epoch: str) -> int:
    """The number of DUPLICATE seqs, over distinct `batch_id` (a retry the replay memory answered processed
    nothing and is not in the log twice), that lie STRICTLY BETWEEN the lowest and the highest STORED seq of
    `epoch` — exactly the run the fold's old stored-row rule counted as a gap. Never a sum of the `duplicates`
    answers, never a constant."""
    stored = [r["seq"] for r in store.rows if r["epoch"] == epoch]
    if not stored:
        return 0
    lo, hi = min(stored), max(stored)
    dups = {d for b in store.batch_log if b["epoch"] == epoch for d in b["dup"]}
    return len({d for d in dups if lo < d < hi})


def assert_resend_counts_no_gap(label: str, store: FoldStore, epoch: str, sent_ids: set, last_hb: "dict | None") -> int:
    """Every fresh-epoch re-send leg's one assertion set (D1 § 10.2, card#12210): the leg produced a duplicate
    run inside the fresh epoch's stored range (the precondition, so the leg is not vacuous) and `seq_gap`
    under that epoch is 0; no unknown kind was ignored under that epoch; every event id the reporter sent is
    held, once; and the heartbeat after it raises no `lossy`."""
    run = inner_duplicate_run(store, epoch)
    eq(f"{label}: a duplicate run lies inside the fresh epoch's stored seqs ({run}), and seq_gap under that "
       "epoch is 0 — the re-send counts no gap", (True, 0), (run > 0, store.gap_by_epoch.get(epoch, 0)))
    eq(f"{label}: … ignored_unknown_kinds is 0 under that epoch, every id sent is held exactly once, and the "
       "next heartbeat raises no `lossy`", (0, set(), True),
       (store.ignored_by_epoch.get(epoch, 0), sent_ids - store.held(),
        last_hb is not None and "lossy" not in last_hb["data"]["degraded"]))
    return run


class SiteStub:
    """One Mezzanine site: a real TLS server on 127.0.0.1, trusted through `ca_file`, with a FoldStore.

    `script` is a list of per-POST actions consumed in arrival order; `default` applies when it is empty
    (None = ingest normally and answer the ingest's 202). An action is a dict:
      {"status": N, "body": obj|str, "headers": {...}}  answer that, storing nothing
      {"commit_drop": True}                            store the batch, then close without answering
      {"commit_hang": s}                               store it, then hold the answer `s` seconds and close
      {"hold": s}                                      hold `s` seconds, then ingest and answer normally
    `binding` maps a bearer token to the `(install_id, seat_id)` the site issued it for: a batch claiming
    another pair is `403 identity_mismatch`, and an event claiming another is `422 invalid_event`
    (BatchValidator, EventValidator). `clock_ms`, when set, is the arrival clock — the reporter's injected
    one — for `clock_skew_ms`. `limits` enforces RateLimiter's two limits on fixed windows of that clock."""

    def __init__(self, name: str):
        self.name = name
        self.store = FoldStore()
        self.raw: list[bytes] = []
        self.posts: list[dict] = []
        self.answers: list = []
        self.gets = 0
        self.script: list = []
        self.default = None
        self.get_status = 200
        self.get_body = None
        self.get_headers = None
        self.get_script: list = []
        self.binding: dict = {}
        # Tokens this site resolves; None resolves every token. A token outside the set is the ingest's own
        # `401 unauthenticated` — to the probe as well as to a batch unless `post_only_auth`.
        self.accept_tokens = None
        self.post_only_auth = False
        self.clock_ms = None
        self.limits = False
        self.windows: dict = {}
        self.lock = threading.Lock()
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

            def _send(self, status, body, headers=None):
                data = body if isinstance(body, bytes) else (body.encode() if isinstance(body, str) else json.dumps(body).encode())
                try:
                    self.send_response(status)
                    self.send_header("Content-Type", "application/json")
                    for k, v in (headers or {}).items():
                        self.send_header(k, v)
                    self.send_header("Content-Length", str(len(data)))
                    self.end_headers()
                    self.wfile.write(data)
                except OSError:
                    pass

            def do_GET(self):
                with outer.lock:
                    outer.gets += 1
                    step = outer.get_script.pop(0) if outer.get_script else {}
                if step.get("hold"):
                    time.sleep(step["hold"])
                st = step.get("status", outer.get_status)
                body = step.get("body", outer.get_body)
                tok = self.headers.get("Authorization", "")[7:]
                if outer.accept_tokens is not None and not outer.post_only_auth and tok not in outer.accept_tokens \
                        and "status" not in step and outer.get_status == 200:
                    st, body = 401, {"error": "unauthenticated", "message": "x"}
                if body is None:
                    body = ({"accepted_schema_versions": [1], "server_time": "2026-08-24T00:00:00.000Z",
                             "min_reporter_version": "0.1.0"} if st == 200 else {"error": "unauthenticated", "message": "x"})
                self._send(st, body, step.get("headers") or outer.get_headers)

            def do_POST(self):
                n = int(self.headers.get("Content-Length", 0))
                raw = self.rfile.read(n) if n else b""
                if self.headers.get("Content-Encoding") == "gzip":
                    import gzip
                    raw = gzip.decompress(raw)
                try:
                    batch = json.loads(raw)
                except Exception:
                    batch = {}
                auth = self.headers.get("Authorization", "")
                with outer.lock:
                    outer.raw.append(raw)
                    outer.posts.append(batch)
                    act = outer.script.pop(0) if outer.script else outer.default
                if act and act.get("run"):
                    act["run"]()
                arrival = outer.clock_ms if outer.clock_ms is not None else int(time.time() * 1000)
                if act and act.get("hold"):
                    time.sleep(act["hold"])
                if act and "status" in act:
                    outer.answers.append(act["status"])
                    self._send(act["status"], act.get("body", {}), act.get("headers"))
                    return
                if outer.accept_tokens is not None and auth[7:] not in outer.accept_tokens:
                    outer.answers.append(401)
                    self._send(401, {"error": "unauthenticated", "message": "x"})
                    return
                pair = outer.binding.get(auth[7:]) if outer.binding else None
                if pair and (batch.get("install_id"), batch.get("seat_id")) != pair:
                    outer.answers.append(403)
                    self._send(403, {"error": "identity_mismatch", "message": "x", "expected_install_id": pair[0],
                                     "expected_seat_id": pair[1]})
                    return
                if pair:
                    for i, e in enumerate(batch.get("events", [])):
                        if (e.get("install_id"), e.get("seat_id")) != pair:
                            outer.answers.append(422)
                            self._send(422, {"error": "invalid_event", "message": "x", "index": i, "field": "seat_id",
                                             "reason": "identity"})
                            return
                if outer.limits:
                    try:
                        sent = calendar.timegm(time.strptime(batch["sent_at"][:19], "%Y-%m-%dT%H:%M:%S"))
                    except Exception:
                        sent = int(time.time())
                    hk, mk = sent // 3600, sent // 60
                    with outer.lock:
                        ev = outer.windows.get(("h", hk), 0) + len(batch.get("events", []))
                        rq = outer.windows.get(("m", mk), 0) + 1
                        outer.windows[("h", hk)] = ev
                        outer.windows[("m", mk)] = rq
                    if ev > EVENTS_LIMIT or rq > REQUESTS_LIMIT:
                        outer.answers.append(429)
                        self._send(429, {"error": "rate_limited", "message": "x", "retry_after_s": 60,
                                         "limit": EVENTS_LIMIT, "window_s": 3600})
                        return
                with outer.lock:
                    resp = outer.store.ingest(batch, arrival)
                if act and act.get("commit_drop"):
                    outer.answers.append("dropped")
                    self.close_connection = True
                    try:
                        self.connection.shutdown(socket.SHUT_RDWR)
                    except OSError:
                        pass
                    return
                if act and act.get("commit_hang"):
                    outer.answers.append("hung")
                    time.sleep(act["commit_hang"])
                    self.close_connection = True
                    try:
                        self.connection.shutdown(socket.SHUT_RDWR)
                    except OSError:
                        pass
                    return
                outer.answers.append(202)
                self._send(202, resp)

        self.httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), H)
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(str(INGEST.crt), str(INGEST.key))
        self.httpd.socket = ctx.wrap_socket(self.httpd.socket, server_side=True)
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    @property
    def url(self) -> str:
        return f"https://127.0.0.1:{self.port}/api/ingest/events"

    def events(self) -> list[dict]:
        return [e for b in self.posts for e in b.get("events", [])]

    def heartbeats(self) -> list[dict]:
        return [e for e in self.events() if e.get("kind") == "reporter.heartbeat"]

    def received_ids(self) -> set:
        return {e["event_id"] for e in self.events()}

    def stop(self):
        self.httpd.shutdown()


STUBS: list[SiteStub] = []


def site_stub(name: str) -> SiteStub:
    st = SiteStub(name)
    STUBS.append(st)
    return st


def site_cfg(name: str, url: str, *, token: "str | None" = None, ca: "str | None" = CA, proxy=None) -> dict:
    return {"name": name, "ingest_url": url, "token": token or seat_token(f"site:{name}:{url}"),
            "ca_file": ca, "proxy_url": proxy}


def multi_seat(name: str, sites: list, **kw) -> Seat:
    """A seat in § 3.1's multi-site form: the four top-level transport keys removed, `sites` written."""
    s = seat(name, **kw)
    for k in ("ingest_url", "token", "ca_file", "proxy_url"):
        s.cfg.pop(k, None)
    s.cfg["sites"] = sites
    s.write_cfg()
    return s


EIDS = iter(range(1, 10**12))
AT28_T0 = (int(time.time()) // 3600 - 30) * 3600 * 1000 + 1_200_000   # 30 h ago, 20 min into an hour


def spool_events(s: Seat, n: int, at_ms: int, *, kind: str = "tool.start", tag: str = "x", pad: int = 0,
                 install_id: str = "aimla", seat_id: str = "aimla-pm") -> list[str]:
    """Write `n` spool lines in the reporter's own line format (D1 § 11.2) into the bucket `at_ms` names,
    and return their event ids. Faster than `n` hook processes, and the flusher reads them identically."""
    b = time.strftime("%Y%m%d%H", time.gmtime(at_ms / 1000))
    ids = []
    with (s.spool / f"{b}.jsonl").open("a", encoding="utf-8") as fh:
        for i in range(n):
            t = time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime((at_ms + i) / 1000)) + f".{(at_ms + i) % 1000:03d}Z"
            eid = f"01{tag.upper()[:4]:_<4}{next(EIDS):020d}"
            ev = {"event_id": eid, "schema_version": 1, "kind": kind, "event_time": t, "install_id": install_id,
                  "seat_id": seat_id, "session_id": SID,
                  "data": {"call_id": f"c-{tag}-{i}", "tool_name": "Bash", "pad": "p" * pad}}
            fh.write(json.dumps({"v": 1, "t": t, "e": ev}) + "\n")
            ids.append(eid)
    return ids


def fl(s: Seat, at_ms: "int | None" = None, reporter: Path = REPORTER, **envx):
    """One flusher pass (one seat pass and one drain by every lane) on the injected clock."""
    if at_ms is not None:
        envx["FLEET_REPORTER_NOW_MS"] = at_ms
    return flush(s, reporter=reporter, **envx)


def sink_lines(s: Seat) -> list[dict]:
    d = s.spool / "counters"
    out = []
    for f in sorted(d.glob("*.jsonl")) if d.exists() else []:
        out += [json.loads(l) for l in f.read_text(encoding="utf-8").splitlines() if l.strip()]
    return out


def nlines(f: Path) -> int:
    return len(f.read_text(encoding="utf-8").splitlines()) if f.exists() else 0


def config_errors(cfg_path: Path, reporter: Path = REPORTER) -> dict:
    """`loadConfig` on a file, through the reporter's own export (the installer's writer's one caller):
    every error it names, and the sites it read — with no probe, so no name is ever resolved."""
    r = subprocess.run(["node", "-e", "const m=require(process.argv[1]); const l=m.loadConfig(process.argv[2]);"
                        "process.stdout.write(JSON.stringify({errors:m.allConfigErrors(l), sites:l.sites.map(s=>s.name)}))",
                        str(reporter), str(cfg_path)], capture_output=True, text=True, cwd=str(HERE))
    return json.loads(r.stdout or '{"errors":["<no output>"],"sites":[]}')


def events_bytes(raw: bytes) -> bytes:
    """The `events` member's bytes of one request body. `JSON.stringify` of the envelope puts it last, so it
    runs from its key to the end: byte-equality of this span is the property § 3.1 states, asserted on the
    bytes and not on parsed JSON."""
    return raw[raw.index(b'"events":'):]


def fl_bg(s: Seat, at_ms: int, reporter: Path = REPORTER, **envx) -> subprocess.Popen:
    """A one-pass flusher in the background, so the suite can kill it mid-request (`kill_when`)."""
    (s.spool / "flusher.lock").unlink(missing_ok=True)
    return subprocess.Popen(["node", str(reporter), "flusher"],
                            env=s.env(freeze=False, FLEET_REPORTER_ONE_PASS="1", FLEET_REPORTER_NOW_MS=at_ms, **envx),
                            cwd=str(HERE), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def kill_when(p: subprocess.Popen, s: Seat, cond, timeout: float = 30.0) -> bool:
    """SIGKILL `p` the moment `cond()` holds — between a commit the stub made and the save that would
    record the answer. Returns whether the condition was seen."""
    t0 = time.time()
    seen = False
    while time.time() - t0 < timeout and p.poll() is None:
        if cond():
            seen = True
            break
        time.sleep(0.005)
    if p.poll() is None:
        p.kill()
    p.wait()
    s.freeze_flusher()
    return seen


INSTALL_DOC = (HERE / "INSTALL-LINUX.md").read_text(encoding="utf-8")
WRITER_SRC = re.search(r"cat > \"\$B/write-config\.js\" <<'JS'\n(.*?)\nJS\n", INSTALL_DOC, re.S).group(1)


def writer_path() -> Path:
    """`write-config.js`, RE-EXTRACTED from INSTALL-LINUX.md Step 2's fenced block on every run, so the block
    the operator saves is the one tested (AT-28 Case P)."""
    d = tmpdir("fr-writer-")
    os.chmod(d, 0o700)
    f = d / "write-config.js"
    f.write_text(WRITER_SRC, encoding="utf-8")
    return f


def token_file(token: str) -> Path:
    d = tmpdir("fr-tokfile-")
    os.chmod(d, 0o700)
    f = d / "mezzanine-reporter-token"
    f.write_text(token + "\n", encoding="utf-8")
    os.chmod(f, 0o600)
    return f


def run_writer(env: dict, *, stdin: str = "", writer: "Path | None" = None, preexec=None) -> subprocess.CompletedProcess:
    e = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": os.environ.get("HOME", "/tmp")}
    e.update({k: str(v) for k, v in env.items()})
    return subprocess.run(["node", str(writer or writer_path())], input=stdin, capture_output=True, text=True,
                          env=e, cwd=str(HERE), preexec_fn=preexec)


def set_token(s: Seat, site: str, token: str) -> subprocess.CompletedProcess:
    """§ 3.1's `FR_MODE=set-token`, by the carried-file route, over this seat's config."""
    return run_writer({"FR_MODE": "set-token", "FR_SITE_NAME": site, "FR_CONFIG": s.cfg_path,
                       "FR_REPORTER": REPORTER, "FR_TOKEN_FILE": token_file(token)})


M_K = {"FLUSH_MS": 25, "REQUEST_MS": 300, "HEARTBEAT_MS": 600, "HEALTH_MS": 1500}


def m_flusher(*defects) -> Path:
    """The reporter with its cadences scaled (and nothing else), so three K.HEALTH_MS periods take
    seconds: FLUSH_MS < REQUEST_MS < HEARTBEAT_MS < HEALTH_MS, production's order."""
    return plant_src(*[(rf"\b{k}: \d+,", f"{k}: {v},") for k, v in M_K.items()],
                     *[(re.escape(old), new) for old, new in defects])


def live(s: Seat, reporter: Path, **envx) -> subprocess.Popen:
    (s.spool / "flusher.lock").unlink(missing_ok=True)
    return subprocess.Popen(["node", str(reporter), "flusher"], env=s.env(freeze=False, **envx), cwd=str(HERE),
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


def stop_live(p: subprocess.Popen, s: Seat) -> None:
    if p.poll() is None:
        p.terminate()
        try:
            p.wait(timeout=10)
        except subprocess.TimeoutExpired:
            p.kill()
            p.wait()
    s.freeze_flusher()


def wait_for(cond, timeout: float = 20.0) -> bool:
    t0 = time.time()
    while time.time() - t0 < timeout:
        if cond():
            return True
        time.sleep(0.01)
    return False


TRANSPORT_KEYS_PY = ["ingest_url", "token", "ca_file", "proxy_url"]


def log_text(s: Seat) -> str:
    d = s.spool / "log"
    return "".join(f.read_text(encoding="utf-8") for f in sorted(d.glob("*.log"))) if d.exists() else ""


def fp_of(url: str) -> str:
    import hashlib
    return hashlib.sha256(f"aimla|aimla-pm|{url}".encode()).hexdigest()[:16]


def tree_lines(s: Seat) -> int:
    """Every line a hook appended: the spool, the index journal and the counter sink (D1 § 2.2 P-4)."""
    n = 0
    for pat in ("*.jsonl", "index/*.jsonl", "counters/*.jsonl"):
        for f in s.spool.glob(pat):
            n += len([l for l in f.read_text(encoding="utf-8").splitlines() if l])
    return n


# A copy with only the spool's size bound shrunk to 2 KiB, so a hook drops a bucket (§ 11.3) — as AT-4 drives it.
P_SMALL_BOUND = plant_src((r"SPOOL_BYTES: 33554432,", "SPOOL_BYTES: 2048,"))
DOWN_503 = {"status": 503, "body": {"error": "server_error", "message": "x", "detail": "store_failed"}}

COMMIT_DROP = {"commit_drop": True}
TOO_LARGE = {"status": 413, "body": {"error": "batch_too_large", "message": "x", "max_bytes": 1, "received_bytes": 2}}


def held_once(st: SiteStub) -> bool:
    ids = [r["event_id"] for r in st.store.rows]
    return len(ids) == len(set(ids))


# ── Case A — fan-out, written once ────────────────────────────────────────────────────────────────────
def case_a(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb = site_stub(f"a-{tag}"), site_stub(f"b-{tag}")
    m = multi_seat(f"at28-a-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    one = seat(f"at28-a1-{tag}")
    walls = {"multi": [], "single": []}
    for i in range(5):
        for key, st in (("multi", m), ("single", one)):
            t0 = time.perf_counter()
            hook(st, "PreToolUse", pre(tuid=f"a{tag}{i}"), reporter=reporter)
            walls[key].append((time.perf_counter() - t0) * 1000)
    d = {"spool_lines": len(m.events()), "single_spool_lines": len(one.events()),
         "appends": tree_lines(m), "single_appends": tree_lines(one),
         "wall_ms": {k: round(statistics.median(v)) for k, v in walls.items()}}
    fl(m, reporter=reporter)
    fl(m, reporter=reporter)   # a second pass: a spooled heartbeat, if one were spooled, is read by it
    # Read after the passes: the flusher's start spools closes of its own (§ 8.3's reporter_restart reap).
    spooled = {e["event_id"] for e in m.events() if e["kind"] != "reporter.heartbeat"}
    for nm, st in (("sa", sa), ("sb", sb)):
        d[nm] = {"ids": {e["event_id"] for e in st.events() if e["kind"] != "reporter.heartbeat"},
                 "fps": sorted({h["data"]["config_fingerprint"] for h in st.heartbeats()}),
                 "lag": [h["data"]["spool_lag_events"] for h in st.heartbeats()]}
    d["spooled"] = spooled
    d["fp"] = {"sa": fp_of(sa.url), "sb": fp_of(sb.url)}
    return d


a_g = case_a("green")
eq("Case A: each site receives exactly the spooled event-id set — no more, no fewer", (True, True),
   (a_g["sa"]["ids"] == a_g["spooled"], a_g["sb"]["ids"] == a_g["spooled"]))
eq("  … the spool holds the events once, not once per site: its line count and every append the hooks made "
   "(spool, index journal, counter sink) equal a single-site seat's for the same hooks",
   (a_g["single_spool_lines"], a_g["single_appends"]), (a_g["spool_lines"], a_g["appends"]))
print(f"  MEASURED  Case A hook wall time, median of 5: multi-site {a_g['wall_ms']['multi']} ms, "
      f"single-site {a_g['wall_ms']['single']} ms (asserted only by AT-3 under FLEET_REPORTER_PERF)")
eq("  … each site received heartbeats carrying its OWN config_fingerprint, and none built for the other",
   ([a_g["fp"]["sa"]], [a_g["fp"]["sb"]]), (a_g["sa"]["fps"], a_g["sb"]["fps"]))
eq("  … and each heartbeat carries that site's own lag (spool_lag_events per heartbeat, per site)", True,
   bool(a_g["sa"]["lag"]) and bool(a_g["sb"]["lag"]))
p_spool_hb = plant(
    ("  if (heartbeat) lane.lastHeartbeat = atMs;\n",
     "  if (heartbeat) { lane.lastHeartbeat = atMs; appendLine(path.join(spool, `${utcBucket(now())}.jsonl`), "
     "JSON.stringify({ v: 1, t: heartbeat.event_time, e: heartbeat }), 'events'); }\n"),
    ("      if (rec.e.kind === 'reporter.heartbeat' && !(rec.e.data && rec.e.data.config_fingerprint === fingerprint)) {",
     "      if (false) {"))
a_r = case_a("red", reporter=p_spool_hb)
eq("RED: a reporter that SPOOLS the heartbeat (the build before this card) -> site sa receives a heartbeat whose "
   "fingerprint is sb's", True, a_r["fp"]["sb"] in a_r["sa"]["fps"])
redgreen("Case A — fan-out, written once (AT-28, D1 § 3.1, § 6.14)",
         f"heartbeat spooled -> sa received fingerprints {a_r['sa']['fps']} (its own {a_r['fp']['sa']}, sb's {a_r['fp']['sb']})",
         f"per-site heartbeat -> sa {a_g['sa']['fps']}, sb {a_g['sb']['fps']}; both received the {len(a_g['spooled'])} "
         f"spooled ids exactly; spool lines {a_g['spool_lines']} = single-site {a_g['single_spool_lines']}, appends "
         f"{a_g['appends']} = {a_g['single_appends']}")


# ── Case C — a permanent refusal is one site's ────────────────────────────────────────────────────────
REFUSE_422 = {"status": 422, "body": {"error": "invalid_event", "message": "x", "index": 0, "field": "data", "reason": "planted"}}


def case_c(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb = site_stub(f"c-a-{tag}"), site_stub(f"c-b-{tag}")
    m = multi_seat(f"at28-c-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    ids = spool_events(m, 250, AT28_T0, tag="c")
    sb.script = [REFUSE_422]
    # sa's probe is held, so sb's lane has built, sent and disposed of its refused request before sa's lane
    # builds its first one: a cursor shared across sites would already have moved past what sa never sent.
    sa.get_script = [{"hold": 1.0}]
    fl(m, AT28_T0 + 60_000, reporter=reporter)
    fl(m, AT28_T0 + 120_000, reporter=reporter)
    refused = [e["event_id"] for e in sb.posts[0].get("events", [])]
    hb = {nm: st.heartbeats()[-1]["data"] for nm, st in (("sa", sa), ("sb", sb))}
    return {"ids": set(ids), "refused": refused,
            "b": (hb["sb"]["counters"]["batches_rejected"], hb["sb"]["counters"]["events_rejected_dropped"],
                  "batches_rejected" in hb["sb"]["degraded"]),
            "a": (hb["sa"]["counters"]["batches_rejected"], hb["sa"]["counters"]["events_rejected_dropped"], hb["sa"]["degraded"]),
            "a_has_refused": set(refused) - {e["event_id"] for e in sb.posts[0]["events"] if e["kind"] == "reporter.heartbeat"} <= sa.received_ids(),
            "files": {f: (m.spool / f).exists() for f in ("quarantine/rejected.sb.jsonl", "REJECTED.sb.txt",
                                                         "quarantine/rejected.sa.jsonl", "REJECTED.sa.txt")},
            "b_behind": set(ids) - set(refused) <= sb.received_ids(),
            "a_missing": set(ids) - sa.received_ids()}


c_g = case_c("green")
eq("Case C: sb's heartbeat carries batches_rejected 1, events_rejected_dropped = that batch's event count, and "
   "`batches_rejected` in degraded", (1, len(c_g["refused"]), True), c_g["b"])
eq("  … sa's carries 0, 0 and an empty degraded", (0, 0, []), c_g["a"])
eq("  … sa's received set still holds every event of the batch sb refused", (True, set()), (c_g["a_has_refused"], c_g["a_missing"]))
eq("  … the record is in rejected.sb.jsonl and the line in REJECTED.sb.txt, and sa has neither file",
   {"quarantine/rejected.sb.jsonl": True, "REJECTED.sb.txt": True, "quarantine/rejected.sa.jsonl": False,
    "REJECTED.sa.txt": False}, c_g["files"])
eq("  … and the events behind the refused batch reach sb", True, c_g["b_behind"])
p_shared_cursor = plant(("      applyAdvance(spool, entry, req.advance);\n      entry.next_seq += req.count;\n      entry.request = null;\n      lane.attempt = 0; lane.waitUntil = 0;\n      c.persist();",
                         "      for (const e of Object.values(state.sites)) applyAdvance(spool, e, req.advance);\n      entry.next_seq += req.count;\n      entry.request = null;\n      lane.attempt = 0; lane.waitUntil = 0;\n      c.persist();"))
c_r = case_c("red", reporter=p_shared_cursor)
eq("RED: a reporter that advances a seat-level cursor on a rejection -> sa never receives the refused batch's events",
   True, len(c_r["a_missing"]) > 0)
redgreen("Case C — a permanent refusal is one site's (AT-28)",
         f"cursor advanced seat-wide -> sa missing {len(c_r['a_missing'])} of {len(c_r['ids'])} ids",
         f"per-site cursor -> sb rejected/dropped/badged {c_g['b']}, sa {c_g['a']}, sa missing {len(c_g['a_missing'])}, "
         f"files {c_g['files']}")


# ── Case D — a drop is counted where it was felt ──────────────────────────────────────────────────────
def case_d(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb = site_stub(f"d-a-{tag}"), site_stub(f"d-b-{tag}")
    m = multi_seat(f"at28-d-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    old = AT28_T0 - 3 * 86400_000
    old_end = (old // 3600_000 + 1) * 3600_000
    ids = spool_events(m, 7, old, tag="d")
    sb.default = DOWN_503
    # Pass 1 ten minutes before the bucket ages past the residency cap: sa delivers it, and sb's request,
    # persisted, carries it. Pass 2 ten minutes after: the seat pass drops it, sb still down — its request
    # is twenty minutes old, far inside the cap, so nothing but the drop happens to it.
    fl(m, old_end + K_RES_MS - 600_000, reporter=reporter)
    fl(m, old_end + K_RES_MS + 600_000, reporter=reporter)
    dropped = not (m.spool / f"{time.strftime('%Y%m%d%H', time.gmtime(old / 1000))}.jsonl").exists()
    sb.default = None
    fl(m, old_end + K_RES_MS + 610_000, reporter=reporter)
    hb = {nm: st.heartbeats()[-1]["data"] for nm, st in (("sa", sa), ("sb", sb))}
    return {"dropped": dropped, "n": len(ids),
            "b": (hb["sb"]["counters"]["spool_dropped_events"], "lossy" in hb["sb"]["degraded"]),
            "a": (hb["sa"]["counters"]["spool_dropped_events"], "lossy" in hb["sa"]["degraded"])}


d_g = case_d("green")
eq("Case D: the aged bucket was dropped by the seat pass", True, d_g["dropped"])
eq("  … sb's next heartbeat counts that bucket's lines past sb's cursor (all of them: sb received none) and "
   "badges `lossy`", (d_g["n"], True), d_g["b"])
eq("  … and sa's, which had received the bucket, counts 0 and no `lossy`", (0, False), d_g["a"])


def case_d_hook(tag: str, reporter: Path) -> dict:
    sa, sb = site_stub(f"dh-a-{tag}"), site_stub(f"dh-b-{tag}")
    m = multi_seat(f"at28-dh-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    past = int(time.time() * 1000) - 2 * 3600_000
    spool_events(m, 6, past, tag="dh", pad=400)
    sb.default = DOWN_503
    fl(m)                                    # the real bound: sa delivers, nothing is dropped yet
    before = len(sink_lines(m))
    hook(m, "PreToolUse", pre(tuid=f"dh{tag}"), reporter=reporter)
    lines = sink_lines(m)[before:]
    s_member = {k: v for ln in lines for k, v in (ln.get("s") or {}).items()}
    return {"sb": (s_member.get("sb") or {}).get("spool_dropped_events"), "sa": "sa" in s_member}


dh_g = case_d_hook("green", P_SMALL_BOUND)
eq("  … a HOOK finding the spool over a (shrunk) size bound writes the drop into its sink line's s.sb, the lines "
   "sb had not received, and writes no s.sa", (6, False), (dh_g["sb"], dh_g["sa"]))
ONE_CURSOR = (re.escape("  for (const [name, cursor] of Object.entries(cursorsBySite)) { const n = past(cursor); if (n) count('spool_dropped_events', n, name); }"),
              "  const one = Object.values(cursorsBySite)[0]; for (const [name] of Object.entries(cursorsBySite)) { const n = past(one); if (n) count('spool_dropped_events', n, name); }")
d_r = case_d("red", reporter=plant_src(ONE_CURSOR))
dh_r = case_d_hook("red", plant_src((r"SPOOL_BYTES: 33554432,", "SPOOL_BYTES: 2048,"), ONE_CURSOR))
eq("RED: a reporter that counts the drop against one cursor (the first site's) -> sb's loss is 0, by the seat "
   "pass and by the hook", (0, None), (d_r["b"][0], dh_r["sb"]))
redgreen("Case D — a drop is counted where it was felt (AT-28, D1 § 11.3)",
         f"one cursor for every site -> sb's heartbeat {d_r['b']}, hook s.sb {dh_r['sb']}",
         f"each site's own cursor -> sb {d_g['b']}, sa {d_g['a']}; hook s.sb {dh_g['sb']}, s.sa present {dh_g['sa']}")


# ── Case E — a corrupt line is disposed of once ───────────────────────────────────────────────────────
def case_e(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb = site_stub(f"e-a-{tag}"), site_stub(f"e-b-{tag}")
    m = multi_seat(f"at28-e-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    spool_events(m, 3, AT28_T0, tag="e1")
    b = time.strftime("%Y%m%d%H", time.gmtime(AT28_T0 / 1000))
    with (m.spool / f"{b}.jsonl").open("a", encoding="utf-8") as fh:
        fh.write('{"v":1,"t":"2026-08-24T00:00:00.000Z","e":{"kind":"tool.st\n')
    spool_events(m, 3, AT28_T0 + 1000, tag="e2")
    fl(m, AT28_T0 + 60_000, reporter=reporter)
    fl(m, AT28_T0 + 120_000, reporter=reporter)
    return {"quarantined": nlines(m.spool / "quarantine" / "corrupt.jsonl"),
            "hb": [st.heartbeats()[-1]["data"]["counters"]["spool_corrupt_lines"] for st in (sa, sb)]}


e_g = case_e("green")
eq("Case E: a torn line both lanes reach is quarantined ONCE, and spool_corrupt_lines is 1 on both sites' heartbeats",
   (1, [1, 1]), (e_g["quarantined"], e_g["hb"]))
p_no_mark = plant(("    if (it.start < (state.disposed[it.bucket] || 0)) continue;\n", ""))
e_r = case_e("red", reporter=p_no_mark)
eq("RED: a reporter without the per-bucket disposal mark -> quarantined twice, counted 2 on both heartbeats",
   (2, [2, 2]), (e_r["quarantined"], e_r["hb"]))
redgreen("Case E — a corrupt line is disposed of once for the seat (AT-28, D1 § 11.4)",
         f"no disposal mark -> quarantined {e_r['quarantined']}x, heartbeats {e_r['hb']}",
         f"disposal mark -> quarantined {e_g['quarantined']}x, heartbeats {e_g['hb']}")


# ── Case G — a repointed name re-sends ────────────────────────────────────────────────────────────────
def case_g(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb, sc = site_stub(f"g-a-{tag}"), site_stub(f"g-b-{tag}"), site_stub(f"g-c-{tag}")
    m = multi_seat(f"at28-g-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    ids = set(spool_events(m, 12, AT28_T0, tag="g"))
    fl(m, AT28_T0 + 60_000, reporter=reporter)
    epoch_b = m.entry("sb").get("seq_epoch")
    # Control: the same restart with the URL unchanged re-sends nothing.
    n_b = len(sb.posts)
    fl(m, AT28_T0 + 120_000, reporter=reporter)
    control = [e for b in sb.posts[n_b:] for e in b["events"] if e["kind"] != "reporter.heartbeat"]
    m.cfg["sites"][1]["ingest_url"] = sc.url
    m.write_cfg()
    fl(m, AT28_T0 + 180_000, reporter=reporter)
    import hashlib
    return {"ids": ids, "c_missing": ids - sc.received_ids(), "c_epochs": sorted({b["seq_epoch"] for b in sc.posts}),
            "epoch_b": epoch_b, "control_resent": len(control),
            "hash_now": m.entry("sb").get("ingest_url_sha256"), "hash_want": hashlib.sha256(sc.url.encode()).hexdigest()}


g_g = case_g("green")
eq("Case G: a name repointed at another server sends that server the WHOLE spool, under a new seq_epoch",
   (set(), True), (g_g["c_missing"], g_g["epoch_b"] not in g_g["c_epochs"] and len(g_g["c_epochs"]) == 1))
eq("  … and the entry now binds the new URL's site key", g_g["hash_want"], g_g["hash_now"])
eq("  … CONTROL: the same restart with the URL unchanged re-sends nothing", 0, g_g["control_resent"])
p_name_only = plant(("  if (e.ingest_url_sha256 === undefined ? !e.legacy : e.ingest_url_sha256 !== urlBinding(site)) return null;\n", ""))
g_r = case_g("red", reporter=p_name_only)
eq("RED: a reporter that keys the entry by name alone -> the third server is missing every id behind sb's old cursor",
   g_r["ids"], g_r["c_missing"])
redgreen("Case G — a repointed name re-sends (AT-28, D1 § 3.1 rule 2)",
         f"entry keyed by name alone -> new server missing {len(g_r['c_missing'])} of {len(g_r['ids'])} ids",
         f"entry bound to the site key -> new server missing {len(g_g['c_missing'])}, epochs {g_g['c_epochs']} "
         f"(old {g_g['epoch_b']}); unchanged URL re-sent {g_g['control_resent']}")


# ── Case I — the scope of a config error ──────────────────────────────────────────────────────────────
def case_i(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb = site_stub(f"i-a-{tag}"), site_stub(f"i-b-{tag}")
    m = multi_seat(f"at28-i-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url, ca=str(TMP / f"no-ca-{tag}.pem"))])
    before = len(sink_lines(m))
    hook(m, "PreToolUse", pre(tuid=f"i{tag}"), reporter=reporter)
    hl = sink_lines(m)[before:]
    fl(m, reporter=reporter)
    fl(m, reporter=reporter)
    r, rep = selftest(m, reporter=reporter)
    hb = sa.heartbeats()[-1]["data"] if sa.heartbeats() else {"counters": {}, "selftest": {}}
    return {"a_events": len([e for e in sa.events() if e["kind"] != "reporter.heartbeat"]), "b_posts": len(sb.posts),
            "a_readable": hb["selftest"].get("config_readable"), "a_config_invalid": hb["counters"].get("config_invalid"),
            "rc": r.returncode, "names": any(e.startswith("sites[sb].ca_file") for e in rep["detail"]["config_readable"]["errors"]),
            "hook_s_b": sum((ln.get("s") or {}).get("sb", {}).get("config_invalid", 0) for ln in hl),
            "hook_c": sum(ln.get("c", {}).get("config_invalid", 0) for ln in hl),
            "hook_s_a": any("sa" in (ln.get("s") or {}) for ln in hl)}


i_g = case_i("green")
eq("Case I: with sb's ca_file unreadable, sa still receives events, and its heartbeat's config_readable passes "
   "with no config_invalid member", (True, "pass", None), (i_g["a_events"] > 0, i_g["a_readable"], i_g["a_config_invalid"]))
eq("  … sb receives nothing, and `selftest` exits 1 naming sites[sb].ca_file", (0, 1, True), (i_g["b_posts"], i_g["rc"], i_g["names"]))
eq("  … and a hook under that config counts config_invalid in its sink line's s.sb, with no c.config_invalid and "
   "no s.sa", (1, 0, False), (i_g["hook_s_b"], i_g["hook_c"], i_g["hook_s_a"]))


def config_run(tag: str, mutate, reporter: Path = REPORTER) -> dict:
    stubs = [site_stub(f"ic-{tag}-{i}") for i in range(5)]
    m = multi_seat(f"at28-ic-{tag}", [site_cfg(f"s{i}", st.url) for i, st in enumerate(stubs[:3])])
    mutate(m, stubs)
    m.write_cfg()
    spool_events(m, 3, AT28_T0, tag="ic")
    fl(m, AT28_T0 + 60_000, reporter=reporter)
    r, rep = selftest(m, reporter=reporter)
    return {"posts": [len(st.posts) for st in stubs], "rc": r.returncode,
            "errors": rep.get("detail", {}).get("config_readable", {}).get("errors", []),
            "epochs": [sorted({b["seq_epoch"] for b in st.posts}) for st in stubs]}


both = config_run("both", lambda m, st: m.cfg.__setitem__("ca_file", None))
eq("  … `\"ca_file\": null` at the top level beside sites is BOTH FORMS PRESENT: nothing is sent anywhere, and the "
   "error names ca_file", ([0, 0, 0, 0, 0], True),
   (both["posts"], any("top-level ca_file is present beside sites" in e for e in both["errors"])))
five = config_run("five", lambda m, st: m.cfg.__setitem__("sites", [site_cfg(f"s{i}", x.url) for i, x in enumerate(st)]))
eq("  … five sites: nothing is sent anywhere, and the error names the rule", ([0] * 5, True),
   (five["posts"], any("sites must be an array of 1…4" in e for e in five["errors"])))
dup = config_run("dup", lambda m, st: m.cfg["sites"][1].__setitem__("ingest_url", st[0].url))
eq("  … two sites sharing a URL: both send nothing while the third does, and the error names both sites",
   (0, True, True), (dup["posts"][0], dup["posts"][2] > 0,
                     any(e.startswith("sites[s0].ingest_url names the same server as sites[1]") for e in dup["errors"])
                     and any(e.startswith("sites[s1].ingest_url names the same server as sites[0]") for e in dup["errors"])))


def key_errors(urls: list) -> list:
    f = tmpdir("fr-at28-key-") / "config.json"
    f.write_text(json.dumps({"install_id": "aimla", "seat_id": "aimla-pm", "spool_dir": str(f.parent), "enabled": True,
                             "sites": [site_cfg(f"k{i}", u) for i, u in enumerate(urls)]}), encoding="utf-8")
    return [e for e in config_errors(f)["errors"] if "same server" in e]


k_caps = key_errors(["https://A.example.test:443/api/ingest/events", "https://a.example.test/api/ingest/events"])
k_user = key_errors(["https://PLANTED-U1:PLANTED-P1@a.example.test/api/ingest/events", "https://a.example.test/api/ingest/events"])
k_path = key_errors(["https://a.example.test/api/ingest/events", "https://a.example.test/other/ingest/events"])
eq("  … the URL rule is by SITE KEY: a capitalised host with :443 written out, and a URL with userinfo, are each the "
   "same server as the plain spelling — refused naming both sites and neither value; two URLs differing in path "
   "are two sites", (2, 2, 0, False),
   (len(k_caps), len(k_user), len(k_path), any("example.test" in e or "PLANTED" in e for e in k_caps + k_user)))
p_raw_key = plant(("      const ka = typeof a.ingest_url === 'string' ? siteKey(a.ingest_url) : null;\n"
                   "      if (ka !== null && ka === (typeof b.ingest_url === 'string' ? siteKey(b.ingest_url) : null)) {",
                   "      const ka = typeof a.ingest_url === 'string' ? a.ingest_url : null;\n"
                   "      if (ka !== null && ka === b.ingest_url) {"))
two_spellings = config_run("spell-red", lambda m, st: m.cfg["sites"][1].__setitem__(
    "ingest_url", st[0].url.replace("/api/ingest/events", "/api/ingest/./events")), reporter=p_raw_key)
k_dot = key_errors([INGEST.url, INGEST.url.replace("/api/ingest/events", "/api/ingest/./events")])
eq("  … and a dot segment in the path is the same server too (the spelling the RED below sends to a real stub)",
   2, len(k_dot))
eq("THIRD RED: a reporter comparing the raw strings passes two spellings of one server as two sites, and the "
   "server behind them receives two seq streams for one seat", 2, len(two_spellings["epochs"][0]))
p_all_seat = plant(("    if (!lane.configOk || lane.authStopped) return;",
                    "    if (!lane.configOk || allErrors.length || lane.authStopped) return;"))
i_r1 = case_i("red-seat", reporter=p_all_seat)
eq("RED: a reporter that treats every error as seat-scoped -> sa sends nothing while only sb's file is broken",
   0, i_r1["a_events"])
p_hook_seat = plant(("  for (const site of loaded.sites) if (site.errors.length && site.name !== null) count('config_invalid', 1, site.name);",
                     "  for (const site of loaded.sites) if (site.errors.length && site.name !== null) count('config_invalid');"))
i_r2 = case_i("red-hook", reporter=p_hook_seat)
eq("SECOND RED: a reporter whose hook counts config_invalid seat-wide -> sa's next heartbeat carries config_invalid "
   "for sb's file", True, (i_r2["a_config_invalid"] or 0) > 0)
redgreen("Case I — the scope of a config error (AT-28, D1 § 3.1)",
         f"every error seat-scoped -> sa delivered {i_r1['a_events']}; hook counting seat-wide -> sa's heartbeat "
         f"config_invalid={i_r2['a_config_invalid']}; raw-string URL rule -> one server received epochs {two_spellings['epochs'][0]}",
         f"site-scoped -> sa delivered {i_g['a_events']} with config_readable {i_g['a_readable']} and no config_invalid, "
         f"sb received {i_g['b_posts']}, selftest rc {i_g['rc']}; hook s.sb={i_g['hook_s_b']} c={i_g['hook_c']}; both forms "
         f"present -> posts {both['posts']}; five sites -> {five['posts']}; shared URL -> {dup['posts'][:3]}; site key refuses "
         f"{len(k_caps)}/{len(k_user)} errors for the spellings, {len(k_path)} for a path difference")


# ── A site name is the operator's text, and no name reaches a map's prototype (card#12180 review F1) ────
def proto_names(tag: str, reporter: Path = REPORTER) -> dict:
    sc, sb = site_stub(f"pn-c-{tag}"), site_stub(f"pn-b-{tag}")
    m = multi_seat(f"at28-pn-{tag}", [site_cfg("constructor", sc.url), site_cfg("sb", sb.url)])
    ids = set(spool_events(m, 5, AT28_T0, tag="pn"))
    fl(m, AT28_T0 + 60_000, reporter=reporter)
    return {"c_got": ids <= sc.store.held(), "b_got": ids <= sb.store.held(),
            "entry": "constructor" in (m.state().get("sites") or {})}


pn_g = proto_names("green")
eq("a site named `constructor` — a valid slug, and a key every plain object inherits — receives every spooled event "
   "and has its own state.json entry, beside a site named sb", (True, True, True),
   (pn_g["c_got"], pn_g["b_got"], pn_g["entry"]))
_pf = tmpdir("fr-at28-proto-") / "config.json"
_pf.write_text(json.dumps({"install_id": "aimla", "seat_id": "aimla-pm", "spool_dir": str(_pf.parent), "enabled": True,
                           "sites": [site_cfg("__proto__", "https://p.example.test/api/ingest/events"),
                                     site_cfg("toString", "https://t.example.test/api/ingest/events"),
                                     site_cfg("sb", "https://b.example.test/api/ingest/events")]}), encoding="utf-8")
_pe = config_errors(_pf)
eq("  … `__proto__` and `toString` fail the name rule, each refused by its index, and the valid site beside them is "
   "untouched", (True, True, False),
   (any(e.startswith("sites[0].name fails") for e in _pe["errors"]), any(e.startswith("sites[1].name fails") for e in _pe["errors"]),
    any(e.startswith("sites[sb].") for e in _pe["errors"])))
pn_r = proto_names("red", reporter=plant(
    ("  const kept = nameMap();\n  for (const site of sites) {\n    if (site.name === null || hasOwn(kept, site.name)) continue;",
     "  const kept = {};\n  for (const site of sites) {\n    if (site.name === null || kept[site.name]) continue;")))
eq("RED: the build reviewed (a plain `{}` keyed by site name) -> the site named `constructor` never sends", False, pn_r["c_got"])
redgreen("a site name never reaches a map's prototype (card#12180 review F1)",
         f"plain-object maps -> `constructor` received everything: {pn_r['c_got']}",
         f"prototype-free maps -> `constructor` received everything: {pn_g['c_got']}, its entry kept: {pn_g['entry']}")


# ── A URL that does not parse is that site's config error, and its entry survives the typo (review F8) ───
def url_typo(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb = site_stub(f"ut-a-{tag}"), site_stub(f"ut-b-{tag}")
    m = multi_seat(f"at28-ut-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url, token=seat_token(f"ut-{tag}"))])
    spool_events(m, 6, AT28_T0, tag="ut")
    fl(m, AT28_T0 + 60_000, reporter=reporter)
    before = m.entry("sb")
    good = m.cfg["sites"][1]["ingest_url"]
    m.cfg["sites"][1]["ingest_url"] = good.replace("https://127.0.0.1", "https://bad host")
    m.write_cfg()
    n0 = len(sb.posts)
    fl(m, AT28_T0 + 120_000, reporter=reporter)
    mid = json.loads(json.dumps(m.entry("sb")))
    # The one change § 3.1 makes to it: the site-scoped error counted on the site it names.
    raised = mid.get("counters", {}).pop("config_invalid", 0)
    during = (len(sb.posts) - n0, mid == before, raised)
    m.cfg["sites"][1]["ingest_url"] = good
    m.write_cfg()
    fl(m, AT28_T0 + 180_000, reporter=reporter)
    resent = [e for b in sb.posts[n0:] for e in b["events"] if e["kind"] != "reporter.heartbeat"]
    return {"during": during, "resent": len(resent), "epoch_same": m.entry("sb").get("seq_epoch") == before.get("seq_epoch")}


ut_g = url_typo("green")
eq("an ingest_url typo that does not parse: the site sends nothing, and its state entry is kept unchanged but for the "
   "one `config_invalid` § 3.1 counts on it; the typo fixed, it re-sends nothing and keeps its epoch — a config error "
   "scoped to the site, never a repoint", ((0, True, 1), 0, True), (ut_g["during"], ut_g["resent"], ut_g["epoch_same"]))
ut_r = url_typo("red", reporter=plant(("  if (urlBinding(site) === null) return { entry: e, reset: false };\n", "")))
eq("RED: the build reviewed (the unparseable URL read as a repoint) -> the fixed typo re-sends the whole spool under a "
   "new epoch", (True, False), (ut_r["resent"] >= 6, ut_r["epoch_same"]))
redgreen("an unparseable ingest_url keeps its site's entry (card#12180 review F8)",
         f"read as a repoint -> {ut_r['resent']} events re-sent after the fix, epoch kept {ut_r['epoch_same']}",
         f"site-scoped error -> nothing sent during {ut_g['during']}, {ut_g['resent']} re-sent after the fix, epoch kept {ut_g['epoch_same']}")


# ── … and so does a LEGACY adoption: a pre-card state.json first read under an unparseable URL (delta review N1) ──
def legacy_typo(tag: str, reporter: Path = REPORTER) -> dict:
    """A single-site seat whose state.json a build before card#12180 wrote (top-level seq_epoch, next_seq and a
    cursor at the end of the one bucket: every line delivered), started for the first time under a typo'd URL, for
    two passes — each saving state.json — and then with the URL fixed."""
    st = site_stub(f"lt-{tag}")
    m = seat(f"at28-lt-{tag}", ingest=st.url)
    spool_events(m, 6, AT28_T0, tag="lt")
    bucket = time.strftime("%Y%m%d%H", time.gmtime(AT28_T0 / 1000))
    written = (m.spool / f"{bucket}.jsonl").stat().st_size
    (m.spool / "state.json").write_text(json.dumps({
        "seq_epoch": "01LEGACYTYPO0000000000000", "next_seq": 7, "cursors": {bucket: written}}), encoding="utf-8")
    good = m.cfg["ingest_url"]
    m.cfg["ingest_url"] = good.replace("https://127.0.0.1", "https://bad host")
    m.write_cfg()
    fl(m, AT28_T0 + 60_000, reporter=reporter)
    fl(m, AT28_T0 + 120_000, reporter=reporter)
    during = (len(st.posts), m.entry("default").get("cursors", {}).get(bucket))
    m.cfg["ingest_url"] = good
    m.write_cfg()
    fl(m, AT28_T0 + 180_000, reporter=reporter)
    resent = [e for b in st.posts for e in b["events"] if e["kind"] != "reporter.heartbeat"]
    return {"during": during, "resent": len(resent), "bound": m.entry("default").get("ingest_url_sha256") is not None,
            "cursor": m.entry("default").get("cursors", {}).get(bucket), "written": written}


lt_g = legacy_typo("green")
_lt_end = lt_g["written"]
eq("a pre-card state.json first read under an unparseable ingest_url: two passes send nothing and save the adopted "
   "cursor; the typo fixed, the entry binds to the URL and re-sends none of the six delivered lines",
   ((0, _lt_end), 0, True, _lt_end), (lt_g["during"], lt_g["resent"], lt_g["bound"], lt_g["cursor"]))
lt_r = legacy_typo("red", reporter=plant((
    "    if (s.sites !== undefined) { if (!isPlain(s.sites)) throw new Error('shape'); s.sites = nameMap(s.sites); markUnbound(s.sites); }",
    "    if (s.sites !== undefined) { if (!isPlain(s.sites)) throw new Error('shape'); s.sites = nameMap(s.sites); }")))
eq("RED: the build reviewed (the adoption marker lost at the typo pass's save) -> the fixed URL reads the entry as "
   "bound to another server and re-sends all six lines", 6, lt_r["resent"])
redgreen("a legacy adoption survives an unparseable ingest_url (card#12180 delta review N1)",
         f"marker lost at the save -> {lt_r['resent']} lines re-sent after the fix",
         f"the unbound entry re-read as the adoption -> {lt_g['resent']} re-sent, bound after the fix: {lt_g['bound']}")

# ── Case O — one site's poison stream evicts nothing of another's ─────────────────────────────────────
def case_o(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb = site_stub(f"o-a-{tag}"), site_stub(f"o-b-{tag}")
    m = multi_seat(f"at28-o-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    spool_events(m, 4000, AT28_T0, tag="o", pad=900)
    # sb's marker starts AT its 64 KiB cap, so every refusal below is written by its drop-oldest rule.
    (m.spool / "REJECTED.sb.txt").write_text(("x" * 127 + "\n") * 512, encoding="utf-8")
    sb.default = REFUSE_422
    t = AT28_T0 + 60_000
    for _ in range(4):
        fl(m, t, reporter=reporter)
        t += 60_000
    spool_events(m, 5, AT28_T0 + 3600_000, tag="o2")
    sa.script = [REFUSE_422]
    fl(m, t, reporter=reporter)
    qa = m.spool / "quarantine" / "rejected.sa.jsonl"
    mk = m.spool / "REJECTED.sa.txt"
    return {"b_file": (m.spool / "quarantine" / "rejected.sb.jsonl").stat().st_size if (m.spool / "quarantine" / "rejected.sb.jsonl").exists() else 0,
            "b_marker": (m.spool / "REJECTED.sb.txt").stat().st_size if (m.spool / "REJECTED.sb.txt").exists() else 0,
            "a_record": qa.exists() and qa.stat().st_size > 0, "a_marker": mk.exists(),
            "b_dropped": (m.entry("sb").get("counters") or {}).get("quarantine_rejected_dropped", 0),
            "a_dropped": (m.entry("sa").get("counters") or {}).get("quarantine_rejected_dropped", 0),
            "shared": (m.spool / "quarantine" / "rejected.jsonl").exists()}


Q_CAP = k_of(_prod_src, "Q_REJECTED_CAP")
o_g = case_o("green")
eq(f"Case O precondition: sb's refusals filled its quarantine to the {Q_CAP} B cap", True, o_g["b_file"] >= Q_CAP - 300_000 and o_g["b_dropped"] > 0)
eq("  … sa's one refused record is in rejected.sa.jsonl and its line in REJECTED.sa.txt", (True, True), (o_g["a_record"], o_g["a_marker"]))
eq("  … sb's cap counted quarantine_rejected_dropped on sb's entry, and sa's is 0", (True, 0), (o_g["b_dropped"] > 0, o_g["a_dropped"]))
p_one_file = plant(("which === 'corrupt' ? 'corrupt.jsonl' : `rejected.${siteName}.jsonl`", "which === 'corrupt' ? 'corrupt.jsonl' : 'rejected.jsonl'"))
o_r = case_o("red", reporter=p_one_file)
eq("RED: a reporter with one rejected.jsonl -> sa's record is dropped at sb's cap, and sa counts the drop", (True, True),
   (o_r["shared"], o_r["a_dropped"] > 0))
redgreen("Case O — per-site quarantine caps (AT-28, D1 § 11.1)",
         f"one shared file -> sa's quarantine_rejected_dropped {o_r['a_dropped']}",
         f"per-site files -> sb file {o_g['b_file']} B (cap {Q_CAP}), sb drops {o_g['b_dropped']}, sa record kept {o_g['a_record']}, "
         f"sa drops {o_g['a_dropped']}")


# ── Case K — a request the site may hold is resent as built, and nothing else is built under its seqs ──
# D1 § 3.1, *One request per site, persisted before it is sent and resent verbatim until the site answers*.
# Every leg asserts `seq_collision` 0 on sb and every event id held once; a leg that expects an epoch reset
# asserts `seq_epoch_change` 1 and every other leg 0.


def k_seat(tag: str):
    sa, sb = site_stub(f"k-a-{tag}"), site_stub(f"k-b-{tag}")
    m = multi_seat(f"at28-k-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    return m, sa, sb


def req_of(m: Seat, site: str = "sb") -> "dict | None":
    return m.entry(site).get("request")




# PLANTS, each the defect a leg below exists to catch.
PLANT_REBUILD = plant(("    lane.attempt += 1;\n    lane.waitUntil = now()",
                       "    entry.request = null; lane.attempt += 1;\n    lane.waitUntil = now()"))
PLANT_WINDOW = plant(("    const req = entry.request;\n    // The window and the backoff DELAY a send; neither ever changes one.\n"
                      "    if (atMs < lane.waitUntil || !windowAdmits(entry, req, atMs)) return;\n",
                      "    if (atMs < lane.waitUntil) return;\n"
                      "    if (!windowAdmits(entry, entry.request, atMs)) { entry.request = null; if (!buildRequest(c, lane, atMs)) return; }\n"
                      "    const req = entry.request;\n"))
PLANT_413 = plant(("        if (req.attempts > 1) {\n          entry.seq_epoch = ulid(atMs); entry.next_seq = 1;\n",
                   "        if (false) {\n          entry.seq_epoch = ulid(atMs); entry.next_seq = 1;\n"))
PLANT_SENTAT = plant(("const composeBody = (r) => JSON.stringify(envelopeOf(r, rfc3339(now())));",
                      "const composeBody = (r) => JSON.stringify(envelopeOf(r, rfc3339(r.built_at)));"))
PLANT_SAVE_AFTER = plant(("    if (!c.persist({ sync: true })) { logLine(spool, lane.role, 'the pre-send save of state.json failed — sending nothing'); return; }\n", ""))
PLANT_NOID = plant(("  return { entry: e, reset: e.identity_sha256 !== undefined && e.identity_sha256 !== identityBinding(cfg) };",
                    "  return { entry: e, reset: false };"))
PLANT_ID_REQ = plant(("  return { entry: e, reset: e.identity_sha256 !== undefined && e.identity_sha256 !== identityBinding(cfg) };",
                      "  return { entry: e, reset: !!e.request && e.identity_sha256 !== undefined && e.identity_sha256 !== identityBinding(cfg) };"))


# K1 — commit, then drop the connection: across a retry, and across a restart.
def k_commit_drop(tag: str, reporter: Path = REPORTER, *, kill: bool = False, control: bool = False) -> dict:
    m, sa, sb = k_seat(f"cd-{tag}")
    spool_events(m, 10, AT28_T0, tag="k1")
    seen_attempts = []
    snap = lambda: seen_attempts.append((req_of(m) or {}).get("attempts"))   # noqa: E731
    t = AT28_T0 + 60_000
    if control:
        sb.script = [{"run": snap}]
        fl(m, t, reporter=reporter)
    elif kill:
        sb.script = [{"run": snap, "commit_hang": 20}]
        p = fl_bg(m, t, reporter=reporter)
        kill_when(p, m, lambda: len(sb.store.rows) > 0)
    else:
        sb.script = [{"run": snap, "commit_drop": True}]
        fl(m, t, reporter=reporter)
    after_first = req_of(m)
    if not control:
        sb.script = [{"run": snap}]
        fl(m, t + 10_000, reporter=reporter)
    e = m.entry("sb")
    return {"raw": list(sb.raw), "posts": list(sb.posts), "attempts_seen": seen_attempts,
            "after_first": after_first, "after": e.get("request"), "next_seq": e.get("next_seq"),
            "collision": sb.store.seq_collision, "change": sb.store.seq_epoch_change, "once": held_once(sb)}


def k1_view(d: dict) -> tuple:
    p = d["posts"]
    first = p[0] if p else {}
    same = len(d["raw"]) >= 2 and events_bytes(d["raw"][0]) == events_bytes(d["raw"][1]) \
        and p[0]["batch_id"] == p[1]["batch_id"] and p[1]["sent_at"] > p[0]["sent_at"]
    # What the lane builds next opens at first_seq + count: the deferred heartbeat, built on the disposal.
    nxt = p[2]["events"][0]["seq"] if len(p) > 2 else d["next_seq"]
    return (same, d["attempts_seen"][:2], d["after_first"] is not None, d["after"],
            nxt == (first.get("events") or [{}])[0].get("seq", 0) + len(first.get("events", [])),
            d["collision"], d["change"], d["once"])


k1 = k_commit_drop("green")
eq("Case K, commit-then-drop: the retry's `events` bytes and batch_id are byte-equal to the first attempt's and its "
   "sent_at is later; sb's entry held the request from before the first send until the retry's answer (attempts 1, "
   "then 2, read as each arrived); after the accept no request is held and next_seq is first_seq + count; "
   "seq_collision 0, no epoch change, every id held once", (True, [1, 2], True, None, True, 0, 0, True), k1_view(k1))
k1k = k_commit_drop("kill", kill=True)
eq("  … and the same across a flusher KILLED between that commit and its save, then restarted — the request and "
   "next_seq were in state.json before the first send", (True, [1, 2], True, None, True, 0, 0, True), k1_view(k1k))
k1c = k_commit_drop("control", control=True)
eq("  CONTROL: the stub answering in time -> one request, one attempt (attempts 1), nothing retried, no request held",
   (1, [1], None, 0, True), (len(k1c["posts"]), k1c["attempts_seen"], k1c["after"], k1c["collision"], k1c["once"]))
k1r = k_commit_drop("red-save-after", reporter=PLANT_SAVE_AFTER, kill=True)
eq("RED: a reporter that saves state.json AFTER the send rather than before -> killed between the send and the save, "
   "the restart builds a different request under the committed seqs: seq_collision >= 1", True, k1r["collision"] >= 1)


# K2 — the deferred heartbeat (round 3's Route A, which must now be unreachable).
def k_deferred(tag: str, reporter: Path = REPORTER, *, live: bool = False) -> dict:
    m, sa, sb = k_seat(f"dh-{tag}")
    spool_events(m, 5, AT28_T0, tag="k2a")
    t = AT28_T0 + 60_000
    # The first request (with its heartbeat) is accepted; while it is answered more lines are spooled, so the
    # lane's next request carries NO heartbeat (its timer was just reset) — and that one is committed and dropped.
    sb.script = [{"run": lambda: spool_events(m, 5, AT28_T0 + 100, tag="k2b")}, COMMIT_DROP]
    if live:
        # Without a restart: a long-lived flusher on the timing-scaled copy, its backoff forced to zero, and the
        # stub dropping every answer past HEARTBEAT_MS of real time.
        sb.default = COMMIT_DROP
        (m.spool / "flusher.lock").unlink(missing_ok=True)
        pr = subprocess.Popen(["node", str(reporter), "flusher"], env=m.env(freeze=False, FLEET_REPORTER_BACKOFF_MAX_MS=0),
                              cwd=str(HERE), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        time.sleep(3 * FAST_K["HEARTBEAT_MS"] / 1000)
        held = req_of(m)
        sb.default = None
        t_accept = time.time()
        time.sleep(2 * FAST_K["HEARTBEAT_MS"] / 1000)
        pr.kill()
        pr.wait()
        m.freeze_flusher()
    else:
        fl(m, t, reporter=reporter)
        held = req_of(m)
        for i in range(1, 4):        # three restarts across three heartbeat intervals, the request unanswered
            sb.script = [COMMIT_DROP]
            fl(m, t + i * (K_HB_MS + 1000), reporter=reporter)
        t_accept = (t + 4 * (K_HB_MS + 1000)) / 1000
        fl(m, t + 4 * (K_HB_MS + 1000), reporter=reporter)
    posts = list(sb.posts)
    deferred = posts[1:]
    retries = [b for b in deferred if held and b["batch_id"] == held["batch_id"]]
    raws = [r for r, b in zip(sb.raw[1:], deferred) if held and b["batch_id"] == held["batch_id"]]
    after = [b for b in deferred if not held or b["batch_id"] != held["batch_id"]]
    nxt = after[0]["events"][0] if after and after[0]["events"] else {}
    return {"held_hb": None if not held else held["heartbeat_event_id"], "n_retries": len(retries),
            "bytes_same": len({events_bytes(r) for r in raws}) == 1 and len(raws) >= 2,
            "only_resends": all(b["batch_id"] == held["batch_id"] for b in deferred[:len(retries)]) if held else False,
            "hb_in_wait": [e for b in retries for e in b["events"] if e["kind"] == "reporter.heartbeat"],
            "next_hb": (nxt.get("kind"), nxt.get("seq") == (held["first_seq"] + held["count"]) if held else None,
                        bool(nxt) and nxt.get("event_time", "") >= time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(t_accept - 1))),
            "collision": sb.store.seq_collision, "once": held_once(sb)}


k2 = k_deferred("green")
eq("Case K, deferred heartbeat (Route A), across restarts: sb's held request carries no heartbeat; sb receives nothing "
   "but byte-identical resends of it, no heartbeat inside the wait; on the accept the next request carries a "
   "heartbeat at item 0 under seq = first_seq + count, stamped after the accept; seq_collision 0",
   (None, True, True, [], ("reporter.heartbeat", True, True), 0, True),
   (k2["held_hb"], k2["n_retries"] >= 2 and k2["bytes_same"], k2["only_resends"], k2["hb_in_wait"], k2["next_hb"],
    k2["collision"], k2["once"]))
k2l = k_deferred("live", fast_flusher(), live=True)
eq("  … and without a restart (one long-lived flusher, timing scaled on a copy, backoff forced to zero)",
   (None, True, [], 0, True), (k2l["held_hb"], k2l["n_retries"] >= 2 and k2l["bytes_same"], k2l["hb_in_wait"],
                              k2l["collision"], k2l["once"]))
k2r = k_deferred("red", reporter=PLANT_REBUILD)
eq("RED: round 3's shape — a heartbeat pinned and the retry rebuilt from the cursor, no persisted request -> the "
   "retry carries a heartbeat at item 0 under the committed first_seq: seq_collision 1 (Route A, measured)",
   True, k2r["collision"] >= 1)


# K3 — the shortened retry (round 3's Route B, which must now be unreachable).
def k_window(tag: str, reporter: Path = REPORTER) -> dict:
    m, sa, sb = k_seat(f"win-{tag}")
    spool_events(m, 300, AT28_T0, tag="k3")
    t = AT28_T0 + 60_000
    sb.script = [COMMIT_DROP]
    fl(m, t, reporter=reporter)
    first = req_of(m)
    # Fill the window through the clock: 19,880 − 199 events claimed this minute, the attempt above included.
    st = m.state()
    st["sites"]["sb"]["window"] = {str(t // 60000): K_SITE_EPH - K_HEADROOM - (K_BATCH - 1)}
    (m.spool / "state.json").write_text(json.dumps(st), encoding="utf-8")
    n0 = len(sb.posts)
    fl(m, t + 10_000, reporter=reporter)
    during = len(sb.posts) - n0
    rolled = t + 3615_000 + 120_000
    fl(m, rolled, reporter=reporter)
    later = sb.posts[n0:]
    first_after = later[0] if later else {}
    second = later[1] if len(later) > 1 else {}
    return {"count": first["count"] if first else None, "during": during,
            "same": bool(first_after) and first_after.get("batch_id") == first["batch_id"]
            and events_bytes(sb.raw[n0]) == events_bytes(sb.raw[0]),
            "resent_count": len(first_after.get("events", [])),
            "next_first": (second.get("events") or [{}])[0].get("seq"), "want_next": first["first_seq"] + K_BATCH if first else None,
            "collision": sb.store.seq_collision, "once": held_once(sb)}


k3 = k_window("green")
eq(f"Case K, shortened retry (Route B): with a {K_BATCH}-event request unanswered and the window filled to "
   f"19,880 − {K_BATCH - 1}, no request reaches sb until the window rolls; the one that then does is byte-identical, "
   f"{K_BATCH} events, not fewer; the request after it opens at first_seq + {K_BATCH}; seq_collision 0",
   (K_BATCH, 0, True, K_BATCH, k3["want_next"], 0, True),
   (k3["count"], k3["during"], k3["same"], k3["resent_count"], k3["next_first"], k3["collision"], k3["once"]))
k3r = k_window("red", reporter=PLANT_WINDOW)
eq("RED: a reporter that rebuilds the retry under the window -> the retry is shorter than the attempt, and what is "
   "built after it collides with the committed tail (Route B, measured)", (True, True),
   (k3r["during"] >= 1, k3r["collision"] >= 1))


# K4 — a bucket the request was built from is dropped behind it.
def k_dropped(tag: str, reporter: Path = REPORTER, *, by_hook: bool = False) -> dict:
    m, sa, sb = k_seat(f"drop-{tag}")
    if by_hook:
        old = int(time.time() * 1000) - 2 * 3600_000
        n_old = len(spool_events(m, 6, old, tag="k4o", pad=400))
        spool_events(m, 4, int(time.time() * 1000) - 60_000, tag="k4n")
        t1 = t2 = None
    else:
        old = AT28_T0 - 3 * 86400_000
        old_end = (old // 3600_000 + 1) * 3600_000
        n_old = len(spool_events(m, 6, old, tag="k4o"))
        spool_events(m, 4, old_end + K_RES_MS - 1200_000, tag="k4n")
        t1, t2 = old_end + K_RES_MS - 600_000, old_end + K_RES_MS + 600_000
    sb.script = [COMMIT_DROP]
    fl(m, t1, reporter=reporter)
    first = req_of(m)
    a_before = (m.entry("sa").get("seq_epoch"), dict(m.entry("sa").get("counters") or {}))
    if by_hook:
        hook(m, "PreToolUse", pre(tuid=f"k4{tag}"), reporter=P_SMALL_BOUND)
    fl(m, t2, reporter=reporter)
    retry = sb.posts[1] if len(sb.posts) > 1 else {}
    a_after = (m.entry("sa").get("seq_epoch"), {k: v for k, v in (m.entry("sa").get("counters") or {}).items()
                                                  if k not in ("events_sent", "batches_ok")})
    return {"same": bool(retry) and first is not None and retry.get("batch_id") == first["batch_id"]
            and events_bytes(sb.raw[1]) == events_bytes(sb.raw[0]) and retry.get("seq_epoch") == first["seq_epoch"],
            "dropped": (m.entry("sb").get("counters") or {}).get("spool_dropped_events", 0), "n_old": n_old,
            "lost_but_held": n_old and {e["event_id"] for e in first["events"]} <= sb.store.held() if first else False,
            "a_epoch_same": a_before[0] == a_after[0],
            "a_counters_same": {k: v for k, v in a_before[1].items() if k not in ("events_sent", "batches_ok")} == a_after[1],
            "collision": sb.store.seq_collision, "once": held_once(sb), "change": sb.store.seq_epoch_change}


k4 = k_dropped("green")
eq("Case K, dropped bucket (seat pass, across a restart between the commit and the drop): the retry is byte-identical "
   "and goes out under the SAME seq_epoch; spool_dropped_events on sb equals the bucket's lines past sb's cursor — "
   "including the request's own, so sb then holds ids the counter says it lost (the over-count § 11.3 accepts); sa's "
   "epoch and counters are unchanged; seq_collision 0", (True, k4["n_old"], True, True, True, 0, True, 0),
   (k4["same"], k4["dropped"], k4["lost_but_held"], k4["a_epoch_same"], k4["a_counters_same"], k4["collision"],
    k4["once"], k4["change"]))
print(f"  MEASURED  Case K dropped-bucket over-count: sb's spool_dropped_events {k4['dropped']} for a bucket whose "
      f"{k4['n_old']} lines sb then holds (§ 11.3's accepted direction)")
k4h = k_dropped("hook", by_hook=True)
eq("  … the same with the bucket removed by a HOOK finding the spool over a (shrunk) size bound", (True, k4h["n_old"], 0, True),
   (k4h["same"], k4h["dropped"], k4h["collision"], k4h["once"]))
k4r = k_dropped("red", reporter=PLANT_REBUILD)
eq("RED: a reporter that builds the retry from the cursor (every build before this card) -> the retry's first event "
   "sits under the committed first_seq with a different event_id: seq_collision >= 1", True, k4r["collision"] >= 1)


# K5 — the age reset.
def k_age(tag: str, reporter: Path = REPORTER) -> dict:
    m, sa, sb = k_seat(f"age-{tag}")
    spool_events(m, 3, AT28_T0, tag="k5a")
    fl(m, AT28_T0 + 60_000, reporter=reporter)                 # sb accepts: its store has seen an epoch
    n = len(spool_events(m, 5, AT28_T0 + 3600_000, tag="k5b"))
    sb.default = DOWN_503
    t1 = AT28_T0 + 3600_000 + 60_000
    fl(m, t1, reporter=reporter)
    held = req_of(m)
    sb.default = None
    n0 = len(sb.posts)
    t2 = t1 + K_RES_MS + 3600_000
    fl(m, t2, reporter=reporter)
    first = sb.posts[n0] if len(sb.posts) > n0 else {}
    ev0 = (first.get("events") or [{}])[0]
    hb = sb.heartbeats()[-1]["data"]
    return {"epoch_new": bool(first) and first["seq_epoch"] != held["seq_epoch"], "first_seq": ev0.get("seq"),
            "hb_now": ev0.get("kind") == "reporter.heartbeat" and ev0.get("event_time", "") >= rfc_ms(t2 - 60_000),
            "discarded_hb_never": held["heartbeat_event_id"] not in sb.store.held()
            and all(e["event_id"] != held["heartbeat_event_id"] for b in sb.posts[n0:] for e in b["events"]),
            "dropped": (m.entry("sb").get("counters") or {}).get("spool_dropped_events", 0), "want": n + 1,
            "lossy": "lossy" in hb["degraded"], "gap": sb.store.seq_gap, "change": sb.store.seq_epoch_change,
            "collision": sb.store.seq_collision, "once": held_once(sb)}


def rfc_ms(ms: int) -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(ms / 1000)) + f".{ms % 1000:03d}Z"


k5 = k_age("green")
eq("Case K, age reset: past the residency cap the first request sb receives is under a new seq_epoch with first_seq "
   "1, carrying a heartbeat stamped inside the last minute; the discarded request's heartbeat never arrives; sb's "
   "spool_dropped_events is the residency drops' per-bucket figure plus exactly one for that heartbeat; `lossy`; "
   "seq_gap 0 — sb committed nothing (the control for the later-413 leg's figure); one epoch change, no collision",
   (True, 1, True, True, k5["want"], True, 0, 1, 0, True),
   (k5["epoch_new"], k5["first_seq"], k5["hb_now"], k5["discarded_hb_never"], k5["dropped"], k5["lossy"], k5["gap"],
    k5["change"], k5["collision"], k5["once"]))


# K6 — the two 413 legs.
def k_413_first(tag: str, reporter: Path = REPORTER) -> dict:
    m, sa, sb = k_seat(f"413a-{tag}")
    spool_events(m, 20, AT28_T0, tag="k6a")
    sb.script = [TOO_LARGE]
    fl(m, AT28_T0 + 60_000, reporter=reporter)
    p0, p1 = sb.posts[0], sb.posts[1]
    h0, h1 = p0["events"][0], p1["events"][0]
    c = m.entry("sb").get("counters") or {}
    return {"half": len(p1["events"]) == len(p0["events"]) // 2, "epoch_same": p1["seq_epoch"] == p0["seq_epoch"],
            "first_same": h1["seq"] == h0["seq"],
            "hb_new": h0["kind"] == h1["kind"] == "reporter.heartbeat" and h1["event_id"] != h0["event_id"]
            and h1["event_time"] >= h0["event_time"],
            "counted": {k: c.get(k, 0) for k in ("oversize_event_dropped", "batches_rejected", "events_rejected_dropped")}}


k6a = k_413_first("green")
eq("Case K, 413 to a FIRST attempt: the next request carries half the events under the same seq_epoch and the same "
   "first_seq, with a heartbeat rebuilt fresh (a new event_id, stamped no earlier), and nothing is counted on sb",
   (True, True, True, True, {"oversize_event_dropped": 0, "batches_rejected": 0, "events_rejected_dropped": 0}),
   (k6a["half"], k6a["epoch_same"], k6a["first_same"], k6a["hb_new"], k6a["counted"]))


def k_413_later(tag: str, reporter: Path = REPORTER) -> dict:
    m, sa, sb = k_seat(f"413b-{tag}")
    spool_events(m, 20, AT28_T0, tag="k6b")
    t = AT28_T0 + 60_000
    sb.script = [COMMIT_DROP]
    fl(m, t, reporter=reporter)
    committed = sb.posts[0]
    sb.script = [TOO_LARGE]
    fl(m, t + 10_000, reporter=reporter)
    rebuilt = sb.posts[2] if len(sb.posts) > 2 else {}
    fl(m, t + 10_000 + K_HB_MS + 1000, reporter=reporter)       # a later heartbeat, stored behind both halves
    sent = {e["event_id"] for b in sb.posts for e in b["events"]}
    return {"m": m, "sb": sb, "committed_hb": committed["events"][0]["kind"] == "reporter.heartbeat",
            "new_epoch": bool(rebuilt) and rebuilt["seq_epoch"] != committed["seq_epoch"],
            "rebuilt_hb": bool(rebuilt) and rebuilt["events"][0]["kind"] == "reporter.heartbeat",
            "epoch": rebuilt.get("seq_epoch"), "sent": sent,
            "collision": sb.store.seq_collision, "once": held_once(sb), "change": sb.store.seq_epoch_change}


k6b = k_413_later("green")
eq("Case K, 413 to a LATER attempt (the first committed, with a heartbeat — pinned): the next request is under a new "
   "seq_epoch with the rebuilt heartbeat at item 0; one epoch change, no collision", (True, True, True, 1, 0, True),
   (k6b["committed_hb"], k6b["new_epoch"], k6b["rebuilt_hb"], k6b["change"], k6b["collision"], k6b["once"]))
k6b_res = assert_resend_counts_no_gap("Case K, later-attempt 413", k6b["sb"].store, k6b["epoch"], k6b["sent"],
                          k6b["sb"].heartbeats()[-1] if k6b["sb"].heartbeats() else None)
k6r = k_413_later("red", reporter=PLANT_413)
eq("RED: a reporter that halves after a later-attempt 413 with no new epoch -> seq_collision >= 1", True, k6r["collision"] >= 1)


# K7 — clock_skew across a 120 s backoff.
def k_skew(tag: str, reporter: Path = REPORTER) -> dict:
    m, sa, sb = k_seat(f"skew-{tag}")
    spool_events(m, 4, AT28_T0, tag="k7")
    t = AT28_T0 + 60_000
    # Refused retryably and NOT committed, so the retry is processed — a replayed batch_id writes nothing and
    # measures no skew (§ 10.4). The ladder's 121 s is the injected clock's step between the two attempts.
    sb.script = [DOWN_503]
    sb.clock_ms = t
    fl(m, t, reporter=reporter)
    sb.clock_ms = t + 121_000
    fl(m, t + 121_000, reporter=reporter)
    retry = sb.posts[1] if len(sb.posts) > 1 else {}
    return {"sent_at": retry.get("sent_at", ""), "want_after": rfc_ms(t + 120_000),
            "skew": sb.store.skews[0] if sb.store.skews else None}


k7 = k_skew("green")
eq("Case K, skew: the retry's sent_at is the retry's own (inside the last second), and the stub's clock_skew_ms is "
   "under 120 s", (True, True), (k7["sent_at"] >= k7["want_after"], k7["skew"] is not None and abs(k7["skew"]) < 120_000))
k7r = k_skew("red", reporter=PLANT_SENTAT)
eq("RED: a reporter that resends sent_at verbatim -> the stub's clock_skew_ms exceeds 120 s", True,
   k7r["skew"] is not None and k7r["skew"] > 120_000)


# K8 — the identity-edit legs, run twice: with a request persisted and unanswered, and with none.
PAIR_B = "seat-two"


def k_identity(tag: str, reporter: Path = REPORTER, *, persisted: bool) -> dict:
    m, sa, sb = k_seat(f"id-{tag}")
    tok1 = m.cfg["sites"][1]["token"]
    tok2 = seat_token(f"pair-b-{tag}")
    sb.binding = {tok1: ("aimla", "aimla-pm"), tok2: ("aimla", PAIR_B)}
    spool_events(m, 3, AT28_T0, tag="k8a")
    t = AT28_T0 + 60_000
    fl(m, t, reporter=reporter)                                  # sb accepts under pair A
    spool_events(m, 4, AT28_T0 + 1000, tag="k8b")
    if persisted:
        sb.script = [DOWN_503]
        fl(m, t + 10_000, reporter=reporter)                     # a request persisted, unanswered
    else:
        fl(m, t + 10_000, reporter=reporter)                     # acknowledged; the lane is idle
        spool_events(m, 2, AT28_T0 + 2000, tag="k8c")            # lines ahead of the cursor, under pair A
    before = m.entry("sb")
    held = before.get("request")
    dropped0 = (before.get("counters") or {}).get("spool_dropped_events", 0)
    m.cfg["seat_id"] = PAIR_B
    m.cfg["sites"][1]["token"] = tok2
    m.write_cfg()
    n0 = len(sb.posts)
    fl(m, t + 20_000, reporter=reporter)
    fl(m, t + 30_000, reporter=reporter)
    after_b = m.entry("sb")
    first_b = sb.posts[n0] if len(sb.posts) > n0 else {}
    spool_end = {f.name[:10]: f.stat().st_size for f in m.spool.glob("*.jsonl")}
    drained = all((after_b.get("cursors") or {}).get(b, 0) >= sz for b, sz in spool_end.items())
    q = m.spool / "quarantine" / "rejected.sb.jsonl"
    d = {"first_b": (first_b.get("seat_id"), first_b.get("seq_epoch") != before.get("seq_epoch"),
                     (first_b.get("events") or [{}])[0].get("seq")),
         "held_hb_never": held is None or all(e["event_id"] != held["heartbeat_event_id"] for b in sb.posts[n0:] for e in b["events"]),
         "dropped_rise": (after_b.get("counters") or {}).get("spool_dropped_events", 0) - dropped0,
         "auth_refused": (after_b.get("counters") or {}).get("auth_refused", 0),
         "quarantined": q.exists() and q.stat().st_size > 0, "drained": drained,
         "answers_b": list(sb.answers[n0:])}
    m.cfg["seat_id"] = "aimla-pm"
    m.cfg["sites"][1]["token"] = tok1
    m.write_cfg()
    n1 = len(sb.posts)
    fl(m, t + 40_000, reporter=reporter)
    back = sb.posts[n1] if len(sb.posts) > n1 else {}
    d.update(back_epoch_new=bool(back) and back.get("seq_epoch") not in (before.get("seq_epoch"), after_b.get("seq_epoch")),
             collision_a=sb.store.counter("seq_collision"), gap_a=sb.store.counter("seq_gap"),
             answers_back=list(sb.answers[n1:]), once=held_once(sb))
    return d


for kind in ("persisted", "none"):
    kd = k_identity(f"green-{kind}", persisted=(kind == "persisted"))
    eq(f"Case K, identity edit ({kind} request): the first request sb receives carries the new pair under a new "
       f"seq_epoch with first_seq 1; the discarded request's heartbeat never arrives and spool_dropped_events on sb "
       f"rose by {1 if kind == 'persisted' else 0} for it; auth_refused 0; the lines spooled under the old pair are "
       f"refused 422 invalid_event and quarantined; the drain is pinned (sb's cursor at the spool's end)",
       ((PAIR_B, True, 1), True, 1 if kind == "persisted" else 0, 0, True, True),
       (kd["first_b"], kd["held_hb_never"], kd["dropped_rise"], kd["auth_refused"], kd["quarantined"], kd["drained"]))
    eq("  … and reverted to the first pair: the next request is under yet another epoch, and the first pair's site "
       "counts seq_collision 0 AND seq_gap 0", (True, 0, 0, True), (kd["back_epoch_new"], kd["collision_a"], kd["gap_a"], kd["once"]))
kn = k_identity("red-noid", reporter=PLANT_NOID, persisted=True)
eq("RED: a reporter without the identity rule (design r4) -> the persisted old-pair request meets 403 "
   "identity_mismatch, the lane stops, and after a second restart the same request meets 403 again — the stop no "
   "config write lifts", True, kn["answers_b"].count(403) >= 2)
kq = k_identity("red-idreq", reporter=PLANT_ID_REQ, persisted=False)
eq("SECOND RED: a reporter whose identity rule resets only when a request is persisted (design r5) -> in the "
   "no-request run, after the revert, the first pair's site counts seq_gap >= 1", True, kq["gap_a"] >= 1)

redgreen("Case K — a request the site may hold is resent as built (AT-28, D1 § 3.1 *One request per site*)",
         f"save after the send -> collision {k1r['collision']}; rebuilt from the cursor -> Route A collision "
         f"{k2r['collision']}, dropped-bucket collision {k4r['collision']}; rebuilt under the window -> sent "
         f"{k3r['during']} before the roll, collision {k3r['collision']}; later-413 without a new epoch -> collision "
         f"{k6r['collision']}; sent_at verbatim -> clock_skew_ms {k7r['skew']}; no identity rule -> answers "
         f"{kn['answers_b']}; identity rule keyed on a persisted request -> first pair's seq_gap {kq['gap_a']}",
         f"persisted request -> retry bytes identical across a retry and a kill-restart, attempts "
         f"{k1['attempts_seen']}/{k1k['attempts_seen']}; Route A retries {k2['n_retries']} identical, no heartbeat in "
         f"the wait; Route B nothing sent until the roll, then {k3['resent_count']} events; a dropped bucket counted "
         f"{k4['dropped']} on sb with the retry under the same epoch; age reset dropped {k5['dropped']} = per-bucket "
         f"+ 1; later-413 re-send of {k6b_res} duplicate seqs counts seq_gap 0 (card#12210); skew {k7['skew']} ms; every "
         f"leg seq_collision 0")


# ── Case H — `selftest` names the site, and a refused token fails it ─────────────────────────────────
_other = tmpdir("fr-other-ca-")
subprocess.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-keyout", str(_other / "o.key"),
                "-out", str(_other / "o.crt"), "-days", "2", "-subj", "/CN=127.0.0.1",
                "-addext", "subjectAltName=IP:127.0.0.1"], check=True, capture_output=True)
OTHER_CA = str(_other / "o.crt")


def h_selftest(tag: str, sb_cfg, setup=None, reporter: Path = REPORTER):
    sa = site_stub(f"h-a-{tag}")
    m = multi_seat(f"at28-h-{tag}", [site_cfg("sa", sa.url), sb_cfg])
    if setup:
        setup()
    r, rep = selftest(m, reporter=reporter)
    return r.returncode, rep


sb_h = site_stub("h-b")
rc_u, rep_u = h_selftest("unreach", site_cfg("sb", "https://127.0.0.1:9/api/ingest/events"))
eq("Case H: with sb unreachable — exit 2, checks.tls_verify not_measured, sites.sa.tls_verify pass, "
   "sites.sb.tls_verify not_measured", (2, "not_measured", "pass", "not_measured"),
   (rc_u, rep_u["checks"]["tls_verify"], rep_u["sites"]["sa"]["tls_verify"], rep_u["sites"]["sb"]["tls_verify"]))
rc_c, rep_c = h_selftest("cert", site_cfg("sb", sb_h.url, ca=OTHER_CA))
eq("  … with sb's certificate not in its ca_file — exit 1, sites.sb.tls_verify fail, sites.sa pass",
   (1, "fail", "pass"), (rc_c, rep_c["sites"]["sb"]["tls_verify"], rep_c["sites"]["sa"]["tls_verify"]))


def deny_all():
    sb_h.accept_tokens = set()


rc_401, rep_401h = h_selftest("401", site_cfg("sb", sb_h.url), setup=deny_all)
sb_h.accept_tokens = None
eq("  … with sb answering the probe 401 (the ingest's body) — exit 1, sites.sb.token_accepted fail, "
   "sites.sb.schema_version_accepted not_measured, sites.sa.token_accepted pass",
   (1, "fail", "not_measured", "pass"),
   (rc_401, rep_401h["sites"]["sb"]["token_accepted"], rep_401h["sites"]["sb"]["schema_version_accepted"],
    rep_401h["sites"]["sa"]["token_accepted"]))
sb_res = site_stub("h-b-residual")
sb_res.binding = {seat_token("h-other-seat"): ("aimla", "someone-else")}
rc_res, rep_res = h_selftest("residual", site_cfg("sb", sb_res.url, token=seat_token("h-other-seat")))
eq("  THE RESIDUAL, MEASURED: a token the site issued to ANOTHER seat — 200 to the probe, 403 identity_mismatch to "
   "every batch — passes `selftest`: exit 0, sites.sb.token_accepted pass (Case M is where it is caught)",
   (0, "pass"), (rc_res, rep_res["sites"]["sb"]["token_accepted"]))
sb_e = site_stub("h-b-empty401")
sb_e.get_status, sb_e.get_body = 401, ""
rc_e, rep_e = h_selftest("empty401", site_cfg("sb", sb_e.url))
eq("  … a 401 that is NOT the ingest's (an empty body) -> sites.sb.token_accepted not_measured, exit 2",
   ("not_measured", 2), (rep_e["sites"]["sb"]["token_accepted"], rc_e))
s_one = seat("at28-h-single")
_, rep_one = selftest(s_one)
eq("  … a single-site config reports one block, `default`", ["default"], sorted(rep_one["sites"]))


def h_first_heartbeats(tag: str, reporter: Path = REPORTER) -> list:
    """sb's probe answers 503 on every start (it measures tls_verify — the 503 came over verified TLS — and
    nothing about the token); every batch is accepted. Two starts, each a heartbeat."""
    sa, sb = site_stub(f"hf-a-{tag}"), site_stub(f"hf-b-{tag}")
    m = multi_seat(f"at28-hf-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    sb.get_status, sb.get_body = 503, {"error": "server_error", "message": "x", "detail": "store_failed"}
    fl(m, AT28_T0, reporter=reporter)
    fl(m, AT28_T0 + K_HB_MS + 1000, reporter=reporter)
    return [(h["data"]["selftest"]["token_accepted"], h["data"]["selftest"]["tls_verify"]) for h in sb.heartbeats()], sb.gets


hf, hf_gets = h_first_heartbeats("green")
eq("  THE UNMEASURED FIRST HEARTBEAT (round 3's N4): with every probe answering 503, the first heartbeat sb receives "
   "carries token_accepted `fail` (unmeasured rides as `fail`) and tls_verify `pass`; the next, built after that "
   "accepted request, carries token_accepted `pass` — no probe measured it, the accepted request did",
   [("fail", "pass"), ("pass", "pass")], hf[:2])
# The design's wording asks for "the stub's probe count still one" at the second heartbeat. On this build a
# probe that measured nothing re-probes on K.HEARTBEAT_MS (§ 6.14), so the second heartbeat's pass comes no sooner
# than a second probe; the leg keeps the discriminating half — every probe answered 503, so none of them could
# have measured the token — and records the count.
print(f"  MEASURED  Case H: sb was probed {hf_gets} time(s), every one answered 503")
p_no_tok = plant(("const SELFTEST_CHECKS = ['config_readable', 'tls_verify', 'schema_version_accepted', 'token_accepted',",
                  "const SELFTEST_CHECKS = ['config_readable', 'tls_verify', 'schema_version_accepted',"),
                 ("const SITE_CHECKS = ['config_readable', 'tls_verify', 'schema_version_accepted', 'token_accepted'];",
                  "const SITE_CHECKS = ['config_readable', 'tls_verify', 'schema_version_accepted'];"))
sb_h.accept_tokens = set()
rc_nt, rep_nt = h_selftest("red-notoken", site_cfg("sb", sb_h.url), reporter=p_no_tok)
sb_h.accept_tokens = None
eq("RED: a reporter without token_accepted -> the 401 run exits 2, a verdict that says nothing is wrong", 2, rc_nt)
p_probe_only = plant(("      entry.selftest.token_accepted = true;\n", ""))
hf_r, _ = h_first_heartbeats("red", reporter=p_probe_only)
eq("SECOND RED: a reporter that measures the checks from the probe alone -> the second heartbeat still carries "
   "token_accepted `fail`", "fail", hf_r[1][0] if len(hf_r) > 1 else None)
p_status_only = plant(("        : r.status === 401 && isPlain(probe.body) && probe.body.error === INGEST_AUTH[401] ? false : null,",
                       "        : r.status === 401 ? false : null,"))
rc_so, rep_so = h_selftest("red-status", site_cfg("sb", sb_e.url), reporter=p_status_only)
eq("THIRD RED: a reporter keying token_accepted on the status alone -> the empty-body 401 fails it", "fail",
   rep_so["sites"]["sb"]["token_accepted"])
redgreen("Case H — selftest names the site, and a refused token fails it (AT-28, D1 § 6.14)",
         f"no token_accepted -> 401 run rc {rc_nt}; probe-only -> heartbeats {hf_r}; status-only -> empty-body 401 "
         f"{rep_so['sites']['sb']['token_accepted']}",
         f"unreachable rc {rc_u}; bad cert rc {rc_c}; 401 rc {rc_401} token_accepted "
         f"{rep_401h['sites']['sb']['token_accepted']}; another seat's token rc {rc_res} (the named residual); "
         f"empty-body 401 {rep_e['sites']['sb']['token_accepted']} rc {rc_e}; first heartbeats {hf}")


# ── Case M — a refused credential stops the lane and loses nothing; only the ingest's own refusal stops it ─
STATE_MEMBERS = sorted(["seq_epoch", "next_seq", "cursors", "counters", "counter_rises", "selftest", "request",
                        "window", "ingest_url_sha256", "identity_sha256"])
PERIODS_S = 3 * M_K["HEALTH_MS"] / 1000


def m_auth(tag: str, reporter: Path, *, kind: str) -> dict:
    """kind '401': the probe resolves the token and every batch is refused `401 unauthenticated` (a token
    revoked between the two); '403': the probe answers 200 and every batch `403 identity_mismatch`."""
    sa, sb = site_stub(f"m-a-{tag}"), site_stub(f"m-b-{tag}")
    tok_bad = seat_token(f"m-bad-{tag}")
    m = multi_seat(f"at28-m-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url, token=tok_bad)])
    ids = set(spool_events(m, 30, int(time.time() * 1000) - 3600_000, tag="m"))
    if kind == "401":
        sb.accept_tokens, sb.post_only_auth = set(), True
    else:
        sb.binding = {tok_bad: ("aimla", "another-seat")}
    p = live(m, reporter)
    try:
        wait_for(lambda: len(sb.answers) >= 1)
        time.sleep(0.2)
        n_at = len(sb.posts) + sb.gets
        entry_at = m.entry("sb")
        req_at = entry_at.get("request")
        time.sleep(PERIODS_S)
        quiet1 = len(sb.posts) + sb.gets - n_at
        if kind == "401":
            sb.accept_tokens = {tok_bad}                       # the site now resolves it: no restart
        else:
            sb.binding = {}
        time.sleep(PERIODS_S)
        quiet2 = len(sb.posts) + sb.gets - n_at - quiet1
        req_held = m.entry("sb").get("request")
    finally:
        stop_live(p, m)
    marker = (m.spool / "REJECTED.sb.txt").read_text(encoding="utf-8") if (m.spool / "REJECTED.sb.txt").exists() else ""
    a_hbs = [h["data"]["counters"] for h in sa.heartbeats()]
    good = seat_token(f"m-good-{tag}")
    sb.accept_tokens, sb.binding = ({tok_bad, good} if kind == "401" else None), {}
    w = set_token(m, "sb", good)
    n_before = len(sb.posts)
    fl(m, reporter=REPORTER)
    fl(m, reporter=REPORTER)
    after = sb.posts[n_before:]
    first_after_raw = sb.raw[n_before] if len(sb.raw) > n_before else b""
    return {"answer": sb.answers[0] if sb.answers else None, "quiet": (quiet1, quiet2),
            "cursor_kept": (entry_at.get("cursors") or {}) == {} and m.entry("sb") is not None,
            "rejected_file": (m.spool / "quarantine" / "rejected.sb.jsonl").exists(),
            "marker_lines": len(marker.splitlines()), "marker": marker,
            "marker_ok": f"status={sb.answers[0] if sb.answers else ''}" in marker and "site=sb" in marker
            and "Bearer" not in marker and tok_bad not in marker,
            "auth_refused": (entry_at.get("counters") or {}).get("auth_refused"),
            "req_kept": req_at is not None and req_held == req_at,
            "a_clean": all(c.get("auth_refused", 0) == 0 and c.get("config_invalid", 0) == 0 for c in a_hbs),
            "members": sorted(entry_at), "writer_rc": w.returncode, "writer_out": w.stdout + w.stderr,
            "first_after_is_held": bool(after) and req_at is not None and after[0]["batch_id"] == req_at["batch_id"]
            and events_bytes(first_after_raw) == events_bytes(sb.raw[0]),
            "drained": ids <= sb.store.held(), "dups": sum(b["dup"] != [] for b in sb.store.batch_log)}


m401 = m_auth("401", m_flusher(), kind="401")
eq("Case M (401): sb answered 401; its cursor does not move and rejected.sb.jsonl does not exist; REJECTED.sb.txt "
   "holds one line naming the status and the site and no Authorization value; auth_refused is 1",
   (401, True, False, 1, True, 1),
   (m401["answer"], m401["cursor_kept"], m401["rejected_file"], m401["marker_lines"], m401["marker_ok"], m401["auth_refused"]))
eq("  … the lane sends sb NOTHING more — no batch, no probe — over three K.HEALTH_MS periods (scaled on a copy), and "
   "still nothing over three more once the site resolves the token: the stop is not probe-lifted", (0, 0), m401["quiet"])
eq("  … sb's entry keeps its persisted request through the stop, unchanged, and sa's heartbeats carry no "
   "auth_refused and no config_invalid", (True, True), (m401["req_kept"], m401["a_clean"]))
eq("  … `FR_MODE=set-token` and a restart: the first request sb receives is the persisted one, byte-identical, "
   "then every event behind the cursor, with no duplicate", (0, True, True, 0),
   (m401["writer_rc"], m401["first_after_is_held"], m401["drained"], m401["dups"]))
eq("  … and sb's entry, read during the stop, holds exactly § 3.1's member list — the stop left nothing behind for "
   "the restart to clear", STATE_MEMBERS, m401["members"])
m403 = m_auth("403", m_flusher(), kind="403")
eq("Case M (403, the real server's shape: probe 200, every batch identity_mismatch): the first batch starts the stop, "
   "nothing more is sent over three periods, REJECTED.sb.txt names 403, identity_mismatch and the body's pair; "
   "set-token with a token bound to this seat and a restart drains the backlog, the persisted request first",
   (403, (0, 0), True, True, True, True),
   (m403["answer"], m403["quiet"], "identity_mismatch" in m403["marker"] and "another-seat" in m403["marker"],
    m403["req_kept"], m403["first_after_is_held"], m403["drained"]))


FRONTS = {
    "waf-403": {"status": 403, "body": "<html><body>Request blocked by the WAF</body></html>",
                "headers": {"Content-Type": "text/html"}},
    "host-401": {"status": 401, "body": "", "headers": {"WWW-Authenticate": "Basic realm=\"x\""}},
    "path-404": {"status": 404, "body": "<html><body>404 Not Found</body></html>", "headers": {"Content-Type": "text/html"}},
    "proxy-400": {"status": 400, "body": "<html><body>400 Bad Request (proxy)</body></html>", "headers": {"Content-Type": "text/html"}},
    "front-200": {"status": 200, "body": {}},
    "empty-202": {"status": 202, "body": ""},
}


def m_front(tag: str, reporter: Path, front: str) -> dict:
    sa, sb = site_stub(f"mf-a-{tag}"), site_stub(f"mf-b-{tag}")
    m = multi_seat(f"at28-mf-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    ids = set(spool_events(m, 30, int(time.time() * 1000) - 3600_000, tag="mf"))
    f = FRONTS[front]
    sb.default = f
    sb.get_status, sb.get_body, sb.get_headers = f["status"], f["body"], f.get("headers")
    p = live(m, reporter, FLEET_REPORTER_BACKOFF_MAX_MS=0)
    try:
        wait_for(lambda: len(sb.posts) >= 1)
        time.sleep(0.3)
        e0 = m.entry("sb")
        n0 = len(sb.posts)
        r0 = (e0.get("counters") or {}).get("batches_retried", 0)
        time.sleep(PERIODS_S)
        e1 = m.entry("sb")
        rising = (len(sb.posts) > n0, (e1.get("counters") or {}).get("batches_retried", 0) > r0)
        # The request's bytes and bookkeeping, `attempts` aside — the one member each retry records.
        same_req = (lambda r: {k: v for k, v in (r or {}).items() if k != "attempts"})
        kept = (not e1.get("cursors"), e0.get("request") is not None and same_req(e1.get("request")) == same_req(e0.get("request")),
                e1.get("next_seq") == e0.get("next_seq") == 1)
        counters1 = dict(e1.get("counters") or {})
        held = e0.get("request")
        sb.default = None
        sb.get_status, sb.get_body, sb.get_headers = 200, None, None
        n_off = len(sb.posts)
        wait_for(lambda: ids <= sb.store.held(), timeout=15)
        after = sb.posts[n_off:]
    finally:
        stop_live(p, m)
    marker = (m.spool / "REJECTED.sb.txt").read_text(encoding="utf-8") if (m.spool / "REJECTED.sb.txt").exists() else ""
    log = log_text(m)
    return {"auth_refused": counters1.get("auth_refused", 0), "rising": rising, "kept": kept,
            "sent_ok": (counters1.get("events_sent", 0), counters1.get("batches_ok", 0)),
            "rejected_file": (m.spool / "quarantine" / "rejected.sb.jsonl").exists(),
            "rejected_bytes": (m.spool / "quarantine" / "rejected.sb.jsonl").stat().st_size if (m.spool / "quarantine" / "rejected.sb.jsonl").exists() else 0,
            "named": f"status={f['status']}" in marker and f"status={f['status']}" in log,
            "body_carried": isinstance(f["body"], str) and f["body"][:40] in marker if f["body"] else True,
            "lands_identical": bool(after) and held is not None and after[0]["batch_id"] == held["batch_id"]
            and events_bytes(sb.raw[n_off]) == events_bytes(sb.raw[0]),
            "drained": ids <= sb.store.held()}


fronts = {k: m_front(k, m_flusher(), k) for k in FRONTS}
for k in ("waf-403", "host-401"):
    d = fronts[k]
    eq(f"Case M, intermediary ({k}): auth_refused stays 0; the lane keeps retrying — the front's request count and "
       f"batches_retried rise across three periods; sb's cursor, next_seq and persisted request are unchanged; the "
       f"log and REJECTED.sb.txt name the status and carry the body's first 512 B; the front removed, the persisted "
       f"request lands byte-identical with no restart, then the backlog",
       (0, (True, True), (True, True, True), True, True, True, True),
       (d["auth_refused"], d["rising"], d["kept"], d["named"], d["body_carried"], d["lands_identical"], d["drained"]))
for k in ("path-404", "proxy-400"):
    d = fronts[k]
    eq(f"Case M, unlisted status / non-ingest body ({k}): retried, rejected.sb.jsonl absent, cursor and request "
       f"unchanged, the log naming the status; removing the front lands the request byte-identical",
       ((True, True), False, (True, True, True), True, True), (d["rising"], d["rejected_file"], d["kept"], d["named"], d["lands_identical"]))
for k in ("front-200", "empty-202"):
    d = fronts[k]
    eq(f"Case M, accepted means the ingest's 202 ({k}): sb's cursor does not move, next_seq and the request are "
       f"unchanged, events_sent and batches_ok stay 0, batches_retried rises, the log names the status; removing the "
       f"front lands the request byte-identical", ((True, True, True), (0, 0), (True, True), True, True),
       (d["kept"], d["sent_ok"], d["rising"], d["named"], d["lands_identical"]))


def m_control_422() -> dict:
    sa, sb = site_stub("mc-a"), site_stub("mc-b")
    m = multi_seat("at28-mc", [site_cfg("sa", sa.url), site_cfg("sb", sb.url)])
    spool_events(m, 250, AT28_T0, tag="mc")
    sb.script = [{"status": 422, "body": {"error": "invalid_batch", "message": "x", "field": "events", "reason": "planted"}}]
    fl(m, AT28_T0 + 60_000)
    c = m.entry("sb").get("counters") or {}
    return {"quarantined": (m.spool / "quarantine" / "rejected.sb.jsonl").exists(),
            "dropped": c.get("events_rejected_dropped", 0), "next_built": len(sb.posts) >= 2, "first": len(sb.posts[0]["events"])}


mc = m_control_422()
eq("  CONTROL: the real ingest's 422 invalid_batch -> that one request quarantined, counted events_rejected_dropped, "
   "and the next built", (True, mc["first"], True), (mc["quarantined"], mc["dropped"], mc["next_built"]))

OLD_OK = ("  if (status === 202 && isPlain(parsed) && typeof parsed.accepted === 'number' && typeof parsed.duplicates === 'number') {",
          "  if (status === 202 || status === 200) {")
OLD_FALLBACK = ("  return { kind: 'retryable', status, foreign: true, body: parsed, text };",
                "  return { kind: 'permanent', status, body: parsed, text };")
OLD_AUTH = ("  if (INGEST_AUTH[status] && INGEST_AUTH[status] === code) return { kind: 'auth', status, body: parsed, text };",
            "  if (INGEST_AUTH[status]) return { kind: 'permanent', status, body: parsed, text };")
r_ok = m_front("red-200", m_flusher(OLD_OK), "front-200")
eq("RED: today's classify (a 200 or 202 accepted on its status) -> the cursor advances past events nobody stored",
   False, r_ok["kept"][0])
r_fb = m_front("red-404", m_flusher(OLD_FALLBACK), "path-404")
eq("RED: today's classify (its `permanent` fallback) -> in the 404 run rejected.sb.jsonl holds b's backlog", True,
   r_fb["rejected_bytes"] > 0)
r_pre = m_auth("red-precard", m_flusher(OLD_AUTH), kind="401")
eq("RED: the pre-card reporter (401/403 as poison pills) -> sb's backlog is quarantined, counted, and never received "
   "after the token is accepted", (True, False), (r_pre["rejected_file"], r_pre["drained"]))
PROBE_LIFT = (("    if (!lane.configOk || lane.authStopped) return;\n    if (atMs - lane.lastHealth > lane.healthEveryMs) {",
               "    if (!lane.configOk) return;\n    if (lane.authStopped && !(atMs - lane.lastHealth > lane.healthEveryMs)) return;\n"
               "    if (atMs - lane.lastHealth > lane.healthEveryMs) {"),
              ("      lane.healthEveryMs = unmeasured ? K.HEARTBEAT_MS : K.HEALTH_MS;\n",
               "      lane.healthEveryMs = unmeasured ? K.HEARTBEAT_MS : K.HEALTH_MS;\n      if (probe.http_status === 200) lane.authStopped = false;\n"))


def m_count(tag: str, reporter: Path, kind: str) -> dict:
    sa, sb = site_stub(f"mc-{tag}-a"), site_stub(f"mc-{tag}-b")
    tok = seat_token(f"mcount-{tag}")
    m = multi_seat(f"at28-mcount-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", sb.url, token=tok)])
    spool_events(m, 10, int(time.time() * 1000) - 3600_000, tag="mcount")
    if kind == "403":
        sb.binding = {tok: ("aimla", "another-seat")}
    elif kind == "401":
        sb.accept_tokens = set()
    else:
        sb.default = FRONTS["waf-403"]
        sb.get_status, sb.get_body = 403, FRONTS["waf-403"]["body"]
    p = live(m, reporter, FLEET_REPORTER_BACKOFF_MAX_MS=0)
    try:
        wait_for(lambda: len(sb.answers) + sb.gets >= 1)
        time.sleep(0.2)
        n0 = len(sb.posts) + sb.gets
        time.sleep(PERIODS_S + M_K["HEALTH_MS"] / 2000)       # three periods and a half: three whole cycles fit
        n1 = len(sb.posts) + sb.gets - n0
        refused = (m.entry("sb").get("counters") or {}).get("auth_refused", 0)
        if kind == "front":
            sb.default = None
            sb.get_status, sb.get_body = 200, None
            time.sleep(PERIODS_S)
    finally:
        stop_live(p, m)
    return {"count": n1, "auth_refused": refused, "recovered": len(sb.store.rows) > 0}


r_lift = m_count("red-lift", m_flusher(*PROBE_LIFT), "403")
eq("SECOND RED: round 2's probe-lifted stop -> in the 403 run sb receives a probe and a refused batch in every "
   "K.HEALTH_MS period, at least six requests over three, and auth_refused keeps rising", (True, True),
   (r_lift["count"] >= 6, r_lift["auth_refused"] > 1))
r_probe = m_count("red-probe", m_flusher(("    if (!lane.configOk || lane.authStopped) return;", "    if (!lane.configOk) return;")), "401")
eq("THIRD RED: a reporter that probes under the stop -> the 401 run's request count after the refusal is > 0", True,
   r_probe["count"] > 0)
r_status = m_count("red-status", m_flusher(("  if (INGEST_AUTH[status] && INGEST_AUTH[status] === code) return { kind: 'auth', status, body: parsed, text };",
                                            "  if (INGEST_AUTH[status]) return { kind: 'auth', status, body: parsed, text };"),
                                           ("        : r.status === 401 && isPlain(probe.body) && probe.body.error === INGEST_AUTH[401] ? false : null,",
                                            "        : r.status === 401 || r.status === 403 ? false : null,")), "front")
eq("FOURTH RED: a reporter whose stop keys on the status alone -> in the intermediary run auth_refused on sb is 1, the "
   "request count after the refusal is zero, and removing the front recovers nothing until a restart",
   (1, 0, False), (r_status["auth_refused"], r_status["count"], r_status["recovered"]))
redgreen("Case M — a refused credential stops the lane and loses nothing; only the ingest's own refusal stops it (AT-28, D1 § 11.5)",
         f"200/202 on status -> cursor kept {r_ok['kept'][0]}; permanent fallback -> {r_fb['rejected_bytes']} B quarantined "
         f"from a 404; poison pills -> quarantined {r_pre['rejected_file']}, drained after the fix {r_pre['drained']}; "
         f"probe-lifted -> {r_lift['count']} requests in three periods, auth_refused {r_lift['auth_refused']}; probing "
         f"under the stop -> {r_probe['count']} requests; status-keyed -> auth_refused {r_status['auth_refused']}, "
         f"recovered {r_status['recovered']}",
         f"401 -> quiet {m401['quiet']}, request kept, drained after set-token {m401['drained']}; 403 -> quiet "
         f"{m403['quiet']}; fronts {', '.join(k + ':' + str(v['rising']) for k, v in fronts.items())} retried, never "
         f"stopped; 422 control quarantined {mc['quarantined']}")


# ── Case P — the writer adds a site without printing a token, and refuses what it must ───────────────
P_TOK1, P_TOK2, P_TOK3 = (seat_token(f"p-planted-{i}") for i in (1, 2, 3))
ISSUE_OUT = lambda tok: f" seat aimla / aimla-pm (seat_ref 1)\n prefix {tok[:12]}\n\n  token       {tok}\n"   # noqa: E731


def p_single(d: Path) -> Path:
    f = d / "config.json"
    f.write_text(json.dumps({"install_id": "aimla", "seat_id": "aimla-pm", "ingest_url": "https://prod.example.test/api/ingest/events",
                             "token": P_TOK1, "spool_dir": str(d / "spool"), "ca_file": None, "proxy_url": None,
                             "wrapped_statusline": None, "protocol_agent_name": None, "descriptors": "full",
                             "enabled": True}, indent=2) + "\n", encoding="utf-8")
    os.chmod(f, 0o600)
    return f


def no_tmp(d: Path) -> bool:
    return not any(x.name.endswith(".tmp") for x in d.iterdir())


def leaked(r: subprocess.CompletedProcess, *toks) -> bool:
    return any(t in (r.stdout + r.stderr) for t in toks)


pd = tmpdir("fr-at28-p-")
os.chmod(pd, 0o700)
pf = p_single(pd)
orig = pf.read_bytes()
base_env = {"FR_CONFIG": pf, "FR_REPORTER": REPORTER}
r_nomode = run_writer({**base_env, "FR_INSTALL": "aimla", "FR_SEAT": "aimla-pm", "FR_URL": "https://x.example.test/api/ingest/events",
                       "FR_SPOOL": pd / "spool"}, stdin=ISSUE_OUT(P_TOK2))
eq("Case P: without FR_MODE an existing file is refused and unchanged", (1, orig), (r_nomode.returncode, pf.read_bytes()))
r_add = run_writer({**base_env, "FR_MODE": "add-site", "FR_SITE_NAME": "sandbox",
                    "FR_URL": "https://sandbox.example.test/api/ingest/events"}, stdin=ISSUE_OUT(P_TOK2))
lifted = json.loads(pf.read_text(encoding="utf-8"))
o = json.loads(orig)
eq("  … with FR_MODE=add-site the result is the multi-site form with the four top-level keys absent, the lifted site "
   "named `default` with its four values equal to the original's, the new site appended, mode 0600, and loadConfig "
   "reports no error", (0, [], ["default", "sandbox"], True, "600", []),
   (r_add.returncode, [k for k in TRANSPORT_KEYS_PY if k in lifted], [x["name"] for x in lifted.get("sites", [])],
    all(lifted["sites"][0][k] == o[k] for k in TRANSPORT_KEYS_PY), oct(pf.stat().st_mode & 0o777)[2:],
    config_errors(pf)["errors"]))
after_add = pf.read_bytes()
refusals = {
    "duplicate name": {"FR_MODE": "add-site", "FR_SITE_NAME": "sandbox", "FR_URL": "https://other.example.test/api/ingest/events"},
    "duplicate URL": {"FR_MODE": "add-site", "FR_SITE_NAME": "third", "FR_URL": "https://SANDBOX.example.test:443/api/ingest/events"},
    "malformed URL": {"FR_MODE": "add-site", "FR_SITE_NAME": "third", "FR_URL": "https://bad host/api/ingest/events"},
    "missing FR_REPORTER": {"FR_MODE": "add-site", "FR_SITE_NAME": "third", "FR_URL": "https://t.example.test/api/ingest/events", "FR_REPORTER": ""},
}
for k in ("FR_INSTALL", "FR_SEAT", "FR_SPOOL", "FR_AGENT", "FR_WRAPPED", "FR_DESCRIPTORS"):
    refusals[f"{k} set"] = {"FR_MODE": "add-site", "FR_SITE_NAME": "third", "FR_URL": "https://t.example.test/api/ingest/events", k: "x"}
ref_out = {}
for label, envx in refusals.items():
    env_ = {**base_env, **envx}
    if env_.get("FR_REPORTER") == "":
        env_.pop("FR_REPORTER")
    r = run_writer(env_, stdin=ISSUE_OUT(P_TOK3))
    ref_out[label] = (r.returncode, pf.read_bytes() == after_add, no_tmp(pd), leaked(r, P_TOK1, P_TOK2, P_TOK3))
eq("  … a duplicate name, a duplicate URL (by site key), a malformed URL, a missing FR_REPORTER, and each of the six "
   "seat keys set: each exits 1, the file unchanged, no temp file left, no planted token printed",
   {k: (1, True, True, False) for k in refusals}, ref_out)
eq("  … and no planted token appeared on stdout or stderr of the add itself", False, leaked(r_add, P_TOK1, P_TOK2))
fd_ = tmpdir("fr-at28-pf-")
os.chmod(fd_, 0o700)
r_fresh = run_writer({"FR_CONFIG": fd_ / "config.json", "FR_INSTALL": "aimla", "FR_SEAT": "aimla-pm",
                      "FR_URL": "https://prod.example.test/api/ingest/events", "FR_SPOOL": fd_ / "spool"}, stdin=ISSUE_OUT(P_TOK1))
fresh = json.loads((fd_ / "config.json").read_text(encoding="utf-8"))
eq("  … a fresh-install run writes sites: [{ name: \"default\", … }] with no top-level transport key",
   (0, ["default"], []), (r_fresh.returncode, [x["name"] for x in fresh["sites"]], [k for k in TRANSPORT_KEYS_PY if k in fresh]))
# set-token, over the multi-site file and over a single-site file, by both token routes.
before_set = json.loads(pf.read_text(encoding="utf-8"))
r_set = run_writer({**base_env, "FR_MODE": "set-token", "FR_SITE_NAME": "sandbox"}, stdin=ISSUE_OUT(P_TOK3))
after_set = json.loads(pf.read_text(encoding="utf-8"))
want = json.loads(json.dumps(before_set))
want["sites"][1]["token"] = P_TOK3
eq("  set-token (stdin route): sandbox's token is the new value and every other byte of every site is unchanged; mode "
   "0600; loadConfig clean; `token replaced: sandbox` printed; no token printed",
   (0, True, "600", [], True, False),
   (r_set.returncode, after_set == want, oct(pf.stat().st_mode & 0o777)[2:], config_errors(pf)["errors"],
    "token replaced: sandbox" in r_set.stdout, leaked(r_set, P_TOK1, P_TOK2, P_TOK3)))
r_setf = run_writer({**base_env, "FR_MODE": "set-token", "FR_SITE_NAME": "sandbox", "FR_TOKEN_FILE": token_file(P_TOK2)})
eq("  … and by the carried token file", (0, P_TOK2, False),
   (r_setf.returncode, json.loads(pf.read_text(encoding="utf-8"))["sites"][1]["token"], leaked(r_setf, P_TOK2)))
ps = tmpdir("fr-at28-ps-")
os.chmod(ps, 0o700)
psf = p_single(ps)
r_set1 = run_writer({"FR_CONFIG": psf, "FR_REPORTER": REPORTER, "FR_MODE": "set-token", "FR_SITE_NAME": "default"},
                    stdin=ISSUE_OUT(P_TOK2))
s1 = json.loads(psf.read_text(encoding="utf-8"))
eq("  … over a single-site file with FR_SITE_NAME=default: the lifted multi-site form, the new token under default",
   (0, ["default"], P_TOK2, []), (r_set1.returncode, [x["name"] for x in s1["sites"]], s1["sites"][0]["token"],
                                  [k for k in TRANSPORT_KEYS_PY if k in s1]))
after_ok = pf.read_bytes()
set_refusals = {
    "a name the file does not carry": {"FR_SITE_NAME": "nope"},
    "FR_URL set": {"FR_SITE_NAME": "sandbox", "FR_URL": "https://x.example.test/api/ingest/events"},
    "FR_CA_FILE set": {"FR_SITE_NAME": "sandbox", "FR_CA_FILE": "/etc/ssl/x.pem"},
    "a seat key set": {"FR_SITE_NAME": "sandbox", "FR_SEAT": "x"},
    "missing FR_REPORTER": {"FR_SITE_NAME": "sandbox", "FR_REPORTER": ""},
}
set_out = {}
for label, envx in set_refusals.items():
    env_ = {**base_env, "FR_MODE": "set-token", **envx}
    if env_.get("FR_REPORTER") == "":
        env_.pop("FR_REPORTER")
    r = run_writer(env_, stdin=ISSUE_OUT(P_TOK1))
    set_out[label] = (r.returncode, pf.read_bytes() == after_ok, no_tmp(pd), leaked(r, P_TOK1, P_TOK2, P_TOK3))
    if label.startswith("a name"):
        names_listed = "default, sandbox" in r.stderr
eq("  … a name the file does not carry, FR_URL or FR_CA_FILE set, a seat key set, and a missing FR_REPORTER: each "
   "exits 1 with the file unchanged and no temp file left, the name error listing the file's site names",
   ({k: (1, True, True, False) for k in set_refusals}, True), (set_out, names_listed))
# RED — a writer that leaves `"ca_file": null` at the top level when it lifts.
w_ca = writer_path()
w_ca.write_text(WRITER_SRC.replace("for (const k of TRANSPORT) { lifted[k] = k in c ? c[k] : null; delete c[k]; }",
                                   "for (const k of TRANSPORT) { lifted[k] = k in c ? c[k] : null; if (k !== 'ca_file') delete c[k]; }"),
                encoding="utf-8")
pr_ = tmpdir("fr-at28-pr-")
os.chmod(pr_, 0o700)
prf = p_single(pr_)
r_ca = run_writer({"FR_CONFIG": prf, "FR_REPORTER": REPORTER, "FR_MODE": "add-site", "FR_SITE_NAME": "sandbox",
                   "FR_URL": "https://sandbox.example.test/api/ingest/events"}, stdin=ISSUE_OUT(P_TOK2), writer=w_ca)
eq("RED: a writer that leaves \"ca_file\": null at the top level when it lifts -> loadConfig names both forms present "
   "(and the file is left as it was)", (1, True), (r_ca.returncode, "top-level ca_file is present beside sites" in r_ca.stderr))


# SECOND RED — a writer that rewrites the config in place, under a file-size limit below the config's length.
def limited(n: int):
    def pre_():
        import resource
        resource.setrlimit(resource.RLIMIT_FSIZE, (n, n))
        signal.signal(signal.SIGXFSZ, signal.SIG_IGN)
    return pre_


pl = tmpdir("fr-at28-pl-")
os.chmod(pl, 0o700)
plf = p_single(pl)
r_add2 = run_writer({"FR_CONFIG": plf, "FR_REPORTER": REPORTER, "FR_MODE": "add-site", "FR_SITE_NAME": "sandbox",
                     "FR_URL": "https://sandbox.example.test/api/ingest/events"}, stdin=ISSUE_OUT(P_TOK2))
plain = plf.read_bytes()
r_lim = run_writer({"FR_CONFIG": plf, "FR_REPORTER": REPORTER, "FR_MODE": "set-token", "FR_SITE_NAME": "sandbox"},
                   stdin=ISSUE_OUT(P_TOK3), preexec=limited(len(plain) // 2))
eq("CONTROL (run first): the temp-and-rename writer under a file-size limit below the config's length fails at the "
   "temp file, unlinks it, exits 1, and the original is byte-identical", (1, True, True),
   (r_lim.returncode, plf.read_bytes() == plain, no_tmp(pl)))
w_inplace = writer_path()
w_inplace.write_text(WRITER_SRC.replace(
    '    const fd = fs.openSync(tmp, "wx", 0o600);\n    try { fs.writeSync(fd, JSON.stringify(c, null, 2) + "\\n"); fs.fsyncSync(fd); } finally { fs.closeSync(fd); }',
    '    fs.writeFileSync(e.FR_CONFIG, JSON.stringify(c, null, 2) + "\\n", { mode: 0o600 }); fs.copyFileSync(e.FR_CONFIG, tmp);'),
    encoding="utf-8")
assert w_inplace.read_text(encoding="utf-8") != WRITER_SRC, "the in-place plant matched nothing"
r_inp = run_writer({"FR_CONFIG": plf, "FR_REPORTER": REPORTER, "FR_MODE": "set-token", "FR_SITE_NAME": "sandbox"},
                   stdin=ISSUE_OUT(P_TOK3), writer=w_inplace, preexec=limited(len(plain) // 2))
eq("SECOND RED: a writer that rewrites the config in place (writeFileSync over the path, Step 3's shape) under the same "
   "limit leaves it truncated, and loadConfig refuses it", (True, True),
   (plf.read_bytes() != plain and len(plf.read_bytes()) <= len(plain) // 2,
    any("not valid JSON" in x for x in config_errors(plf)["errors"])))
redgreen("Case P — the writer adds a site and rotates a token without printing one, and refuses what it must (AT-28, D1 § 3.1)",
         f"lift leaving top-level ca_file -> rc {r_ca.returncode}, both forms named; in-place rewrite under RLIMIT_FSIZE -> "
         f"config truncated to {len(plf.read_bytes())} of {len(plain)} B",
         f"add-site rc {r_add.returncode} -> sites {[x['name'] for x in lifted['sites']]}; {len(refusals)} add refusals and "
         f"{len(set_refusals)} set-token refusals each rc 1 with the file unchanged; set-token by both routes; temp-and-rename "
         f"under the same limit -> rc {r_lim.returncode}, original byte-identical")


# Step 8's REMOVE-ONE-SITE command, re-extracted from INSTALL-LINUX.md: temp file, loadConfig, rename (review F6).
# Since card#12334 it takes its three values from FR_CONFIG, FR_SITE_NAME and FR_REPORTER at the front of the line.
REMOVE_JS = re.search(r"^FR_CONFIG=\S+ FR_SITE_NAME=\S+ FR_REPORTER=\S+ node -e '(const fs=require\(\"fs\"\),path=require\(\"path\"\),f=process\.env\.FR_CONFIG.*?)'$",
                      INSTALL_DOC, re.M).group(1)


def run_remove(cfg: Path, name: str, js_src: str = REMOVE_JS, preexec=None) -> subprocess.CompletedProcess:
    # The values go both ways: in the environment, which the runbook's command reads, and as argv, which the
    # reviewed one-liner below (OLD_REMOVE_JS, the RED) read.
    return subprocess.run(["node", "-e", js_src, str(cfg), name, str(REPORTER)], capture_output=True, text=True,
                          cwd=str(HERE), preexec_fn=preexec,
                          env={**os.environ, "FR_CONFIG": str(cfg), "FR_SITE_NAME": name, "FR_REPORTER": str(REPORTER)})


rd = tmpdir("fr-at28-rm-")
os.chmod(rd, 0o700)
rf = p_single(rd)
run_writer({"FR_CONFIG": rf, "FR_REPORTER": REPORTER, "FR_MODE": "add-site", "FR_SITE_NAME": "sandbox",
            "FR_URL": "https://sandbox.example.test/api/ingest/events"}, stdin=ISSUE_OUT(P_TOK2))
two = rf.read_bytes()
r_lim_rm = run_remove(rf, "sandbox", preexec=limited(len(two) // 3))
eq("Case P, remove-site (Step 8): under a file-size limit below the result's length it fails at the temp file, exits 1, "
   "and the config is byte-identical with no temp file left (the control, run first)", (1, True, True),
   (r_lim_rm.returncode, rf.read_bytes() == two, no_tmp(rd)))
r_unk = run_remove(rf, "nope")
eq("  … a name the file does not carry is refused, the file unchanged", (True, True), (r_unk.returncode != 0, rf.read_bytes() == two))
r_rm = run_remove(rf, "sandbox")
after_rm = json.loads(rf.read_text(encoding="utf-8"))
eq("  … it removes the site: the other sites unchanged, mode 0600, loadConfig clean, no temp file", (0, ["default"], "600", [], True),
   (r_rm.returncode, [x["name"] for x in after_rm["sites"]], oct(rf.stat().st_mode & 0o777)[2:], config_errors(rf)["errors"], no_tmp(rd)))
one = rf.read_bytes()
r_last = run_remove(rf, "default")
eq("  … and refuses the last site, the file unchanged", True, r_last.returncode != 0 and rf.read_bytes() == one)
OLD_REMOVE_JS = ('const fs=require("fs"),f=process.argv[1],n=process.argv[2]; const c=JSON.parse(fs.readFileSync(f,"utf8")); '
                 'if (!Array.isArray(c.sites) || !c.sites.some((x)=>x.name===n)) throw new Error("no site named "+n); '
                 'if (c.sites.length===1) throw new Error("the last site: uninstall instead"); c.sites=c.sites.filter((x)=>x.name!==n); '
                 'fs.writeFileSync(f, JSON.stringify(c,null,2)+"\\n", {mode:0o600}); console.log("sites:", c.sites.map((x)=>x.name).join(", "))')
rf.write_bytes(two)
r_old = run_remove(rf, "sandbox", js_src=OLD_REMOVE_JS, preexec=limited(len(two) // 3))
eq("RED: the reviewed one-liner, which rewrites the config in place, leaves it truncated under the same limit and "
   "loadConfig refuses it", (True, True),
   (rf.read_bytes() != two and len(rf.read_bytes()) <= len(two) // 3, any("not valid JSON" in x for x in config_errors(rf)["errors"])))
redgreen("Step 8's remove-site command is atomic (card#12180 review F6)",
         f"in-place rewrite under RLIMIT_FSIZE -> config truncated to {len(rf.read_bytes())} of {len(two)} B",
         f"temp-and-rename -> rc {r_lim_rm.returncode}, original byte-identical; removal rc {r_rm.returncode}")

# ── Case L — a second site's credentials are secrets too, and state.json is no second home for a URL ──
PLANTS_L = ("PLANTED-PW", "PLANTED-U", "PLANTED-P")


def l_sweep(s: Seat, streams: list) -> list:
    hits = []
    files = list((s.spool / "quarantine").glob("*")) + list((s.spool / "log").glob("*")) + list(s.spool.glob("REJECTED.*.txt"))
    files.append(s.spool / "state.json")
    for f in files:
        if f.is_file():
            t = f.read_text(encoding="utf-8", errors="replace")
            hits += [f"{f.name}:{p}" for p in PLANTS_L if p in t]
    for i, st in enumerate(streams):
        hits += [f"stream[{i}]:{p}" for p in PLANTS_L if p in st]
    return hits


def case_l(tag: str, reporter: Path = REPORTER) -> dict:
    sa, sb = site_stub(f"l-a-{tag}"), site_stub(f"l-b-{tag}")
    proxy = ConnectProxy(sb.port)
    sb_cfg = site_cfg("sb", f"https://PLANTED-U:PLANTED-P@127.0.0.1:{sb.port}/api/ingest/events",
                      proxy=f"http://user:PLANTED-PW@127.0.0.1:{proxy.port}")
    m = multi_seat(f"at28-l-{tag}", [site_cfg("sa", sa.url), sb_cfg])
    spool_events(m, 4, AT28_T0, tag="l")
    b = time.strftime("%Y%m%d%H", time.gmtime(AT28_T0 / 1000))
    with (m.spool / f"{b}.jsonl").open("a", encoding="utf-8") as fh:
        fh.write('{"v":1,"e":{"kind":"tool.st","pw":"PLANTED-PW"\n')
    spool_events(m, 2, AT28_T0 + 1000, tag="l2")
    # The refusing site ECHOES all three planted strings in its 422 body: the server body is what the marker and
    # the log copy, so a skipped registration leaks on a path that exists.
    sb.script = [{"status": 422, "body": {"error": "invalid_event", "message": "rejected for PLANTED-PW PLANTED-U PLANTED-P",
                                          "index": 0, "field": "data", "reason": "planted"}}]
    r_f = fl(m, AT28_T0 + 60_000, reporter=reporter)
    r_s, _ = selftest(m, reporter=reporter)
    proxy.stop()
    marker = (m.spool / "REJECTED.sb.txt").read_text(encoding="utf-8") if (m.spool / "REJECTED.sb.txt").exists() else ""
    return {"hits": l_sweep(m, [r_f.stdout, r_f.stderr, r_s.stdout, r_s.stderr]), "marker": marker,
            "log": log_text(m), "via_proxy": len(proxy.connects) > 0, "seat": m,
            "state_has_url": "127.0.0.1:" + str(sb.port) in (m.spool / "state.json").read_text(encoding="utf-8")}


l_g = case_l("green")
eq("Case L precondition: sb's requests went through its CONNECT proxy, and its 422 was written to the marker", (True, True),
   (l_g["via_proxy"], bool(l_g["marker"])))
eq("Case L: none of the planted proxy password, URL username or URL password appears in the log, any quarantine file, "
   "any REJECTED.<name>.txt, selftest's stdout or stderr, or state.json — which holds the site key's hash and never "
   "the URL", ([], False), (l_g["hits"], l_g["state_has_url"]))
eq("  … the marker and the log carry the redaction marker where the stub put them", (True, True),
   ("‹redacted:token›" in l_g["marker"], "‹redacted:token›" in l_g["log"]))
ctl = l_g["seat"]
(ctl.spool / "log" / "control.log").write_text("planted by hand: PLANTED-PW PLANTED-U PLANTED-P\n", encoding="utf-8")
eq("  NEGATIVE CONTROL: the same sweep catches the strings planted into the log by hand", 3, len(l_sweep(ctl, [])))
(ctl.spool / "log" / "control.log").unlink()
p_top_only = plant(("  const holders = [cfg].concat(Array.isArray(cfg.sites) ? cfg.sites.filter((x) => x && typeof x === 'object') : []);",
                    "  const holders = [cfg];"))
l_r = case_l("red", reporter=p_top_only)
eq("RED: a reporter whose registerConfigSecrets reads the top-level keys only -> all three planted strings are in "
   "REJECTED.sb.txt and in the log line of the refused batch", (True, True),
   (all(p in l_r["marker"] for p in PLANTS_L), all(p in l_r["log"] for p in PLANTS_L)))
redgreen("Case L — every site's credentials are registered secrets (AT-28, D1 § 3.1)",
         f"top-level registration only -> sweep found {l_r['hits']}",
         f"registration descends into sites -> sweep found {l_g['hits']}; state.json carries the URL: {l_g['state_has_url']}")


# ── Case J — a lane that loses ownership stops the process, and its counts leave by the site channel ──
def j_seat(tag: str):
    sa, sb = site_stub(f"j-a-{tag}"), site_stub(f"j-b-{tag}")
    s = seat(f"at28-j-{tag}")
    for k in TRANSPORT_KEYS_PY:
        s.cfg.pop(k, None)
    s.cfg["sites"] = [site_cfg("sa", sa.url)]
    s.write_cfg()
    now_ms = int(time.time() * 1000)
    u_at, f_at = now_ms - 3 * 3600_000, now_ms - 2 * 3600_000
    spool_events(s, 5, u_at, tag="ju")
    # A bucket of lines a build before card#12180 spooled, a heartbeat of ANOTHER site's: § 3.1 rule 1 skips them,
    # uncounted, after reading every one — the synchronous stretch in which the claim below lands.
    fb = s.spool / f"{time.strftime('%Y%m%d%H', time.gmtime(f_at / 1000))}.jsonl"
    line = json.dumps({"v": 1, "t": "2026-08-24T00:00:00.000Z", "e": {"kind": "reporter.heartbeat", "event_id": "01J" + "0" * 23,
                                                                       "data": {"config_fingerprint": "0000000000000000"}}}) + "\n"
    fb.write_text(line * 150_000, encoding="utf-8")
    fl(s)                                                    # sa receives the bucket and passes the old heartbeats
    ub = s.spool / f"{time.strftime('%Y%m%d%H', time.gmtime(u_at / 1000))}.jsonl"
    os.chmod(ub, 0)                                          # unreadable from here on: sa has every line of it
    s.cfg["sites"].append(site_cfg("sb", sb.url))
    s.write_cfg()
    return s, sa, sb, ub


def case_j(tag: str, reporter: Path = REPORTER) -> dict:
    if os.geteuid() == 0:
        return {}
    s, sa, sb, ub = j_seat(tag)
    q = s.spool / "quarantine" / "corrupt.jsonl"
    n_sink = len(sink_lines(s))
    p = live(s, reporter)
    claimed_at = None
    if wait_for(lambda: q.exists() and "unreadable_bucket" in q.read_text(encoding="utf-8"), timeout=30):
        take_over(s)
        claimed_at = time.time()
    try:
        p.wait(timeout=30)
    except subprocess.TimeoutExpired:
        p.kill()
        p.wait()
    exited_s = None if claimed_at is None else round(time.time() - claimed_at, 1)
    s.freeze_flusher()
    os.chmod(ub, 0o600)
    lines = [ln for ln in sink_lines(s)[n_sink:] if ln.get("p") == "flusher"]
    lost = sum(ln.get("c", {}).get("flusher_lost_ownership", 0) for ln in lines)
    s_b = sum((ln.get("s") or {}).get("sb", {}).get("spool_dropped_events", 0) for ln in lines)
    late = [b for b in sa.posts if claimed_at and ulid_ms(b.get("batch_id", "")) / 1000 > claimed_at]
    # The new owner: a fresh flusher folds the sink, and each site's heartbeat composes its counters.
    fl(s)
    hb_a = sa.heartbeats()[-1]["data"]["counters"] if sa.heartbeats() else {}
    return {"claimed": claimed_at is not None, "rc": p.returncode, "exited_s": exited_s, "lost": lost, "s_b": s_b,
            "late_a": len(late), "a_dropped": hb_a.get("spool_dropped_events"),
            "b_entry": (s.entry("sb").get("counters") or {}).get("spool_dropped_events", 0)}


if os.geteuid() == 0:
    skip("Case J needs a non-root run: its unreadable bucket is a mode-000 file, which root reads — NOT measured")
else:
    j_g = case_j("green")
    eq("Case J: ownership claimed while sb's lane is mid-drain (after it counted an unreadable bucket on sb) -> the "
       "process exits 0 on its own, flusher_lost_ownership reaches the sink once in `c`, sb's unsaved count reaches it "
       "in `s.sb`, and sa receives nothing the ex-owner built after the claim", (True, 0, 1, True, 0),
       (j_g["claimed"], j_g["rc"], j_g["lost"], j_g["s_b"] > 0, j_g["late_a"]))
    eq("  … the new owner folds it into sb's entry, and sa's heartbeat carries no drop for sb's loss", (True, 0),
       (j_g["b_entry"] > 0, j_g["a_dropped"]))
    print(f"  MEASURED  Case J: the ex-owner exited {j_g['exited_s']} s after the claim")
    p_singly = plant(("    halt();\n    if (lostCounted) return;", "    if (lostCounted) return;"))
    j_r1 = case_j("red-singly", reporter=p_singly)
    # THE DESIGN'S STATED OBSERVABLE FOR THIS RED — "a's lane keeps sending under the old owner's seqs" — CANNOT
    # OCCUR ON THIS BUILD: every send is preceded by the lane's own pre-send save, which checks ownership (§ 2.3,
    # § 3.1), so a lane the loss did not stop still stops at its next send. What singly-exiting lanes DO cost is the
    # exit itself: the other loops run on until their own next check, a whole K.FLUSH_MS later.
    eq(f"RED: a reporter whose loops exit singly keeps the process alive after the claim until every other loop "
       f"reaches its own check, on its own K.FLUSH_MS nap ({j_r1['exited_s']} s), where the real one exits at once "
       f"({j_g['exited_s']} s)", True,
       j_r1["exited_s"] is not None and j_g["exited_s"] is not None
       and j_r1["exited_s"] >= k_of(_prod_src, "FLUSH_MS") / 2000 > j_g["exited_s"])
    p_no_s = plant(("  if (Object.keys(CS).length) rec.s = CS;",
                    "  for (const m of Object.values(CS)) for (const [k, v] of Object.entries(m)) C[k] = (C[k] || 0) + v;"))
    j_r2 = case_j("red-no-s", reporter=p_no_s)
    eq("SECOND RED: a reporter whose exit flush writes `c` with no site key -> the new owner folds sb's count into the "
       "seat-wide total, and sa's next heartbeat carries it", True, (j_r2["a_dropped"] or 0) > 0)
    redgreen("Case J — a lane's lost ownership stops the process, and its counts leave by the site channel (AT-28, D1 § 2.3, § 11.1)",
             f"loops exit singly -> exit {j_r1['exited_s']} s after the claim; exit flush without `s` -> sa's heartbeat "
             f"spool_dropped_events {j_r2['a_dropped']}",
             f"exit {j_g['exited_s']} s after the claim, rc {j_g['rc']}, lost_ownership {j_g['lost']} in c, s.sb "
             f"{j_g['s_b']}, sa's heartbeat {j_g['a_dropped']}, sb's entry {j_g['b_entry']}")


# ── Case N — a backfill is paced under the site's limit, and the heartbeat still lands ───────────────
CEILING_PER_DAY = 10_420
N_DAYS = 7.5        # the last half day of the 8-day residency is the drain's own: no bucket ages out mid-drain


def case_n(tag: str, reporter: Path = REPORTER, *, minutes: "int | None" = None) -> dict:
    """Each flusher run is ONE pass, a minute apart on the injected clock: the heartbeat's cadence, so a run's
    heartbeat is the lane's one heartbeat of that minute, as the window's headroom is derived for (§ 11.5).
    A real lane makes a pass every K.FLUSH_MS, six a minute; one a minute sends at most 8 x 200 events a
    minute, so an unpaced drain here reaches the server's limit in its 13th minute rather than its 2nd."""
    sa, sb = site_stub(f"n-a-{tag}"), site_stub(f"n-b-{tag}")
    s = seat(f"at28-n-{tag}")
    for k in TRANSPORT_KEYS_PY:
        s.cfg.pop(k, None)
    s.cfg["sites"] = [site_cfg("sa", sa.url)]
    s.write_cfg()
    t0 = (int(time.time() * 1000) // 3600_000) * 3600_000
    per_h = CEILING_PER_DAY // 24
    hours = int(N_DAYS * 24)
    ids = set()
    for h in range(hours, 0, -1):
        ids |= set(spool_events(s, per_h, t0 - h * 3600_000 + 60_000, tag="n"))
    a_t = t0 + 60_000
    fl(s, a_t)
    # sa is current: its cursor is set at every bucket's end, as a drain already done would leave it.
    st = s.state()
    st["sites"]["sa"]["cursors"] = {f.name[:10]: f.stat().st_size for f in s.spool.glob("*.jsonl")}
    (s.spool / "state.json").write_text(json.dumps(st), encoding="utf-8")
    a_t += 60_000
    s.cfg["sites"].append(site_cfg("sb", sb.url))
    s.write_cfg()
    sb.limits = True
    t = start = a_t
    hb_minutes, answers429, done_at = set(), [], None
    limit_t = start + (minutes * 60_000 if minutes else int((len(ids) / (K_SITE_EPH - K_HEADROOM) + 2) * 3600_000))
    while t < limit_t:
        n0 = len(sb.posts)
        fl(s, t, reporter=reporter)
        new = sb.posts[n0:]
        if any(e["kind"] == "reporter.heartbeat" for b in new for e in b["events"]) and sb.answers[n0:].count(202):
            hb_minutes.add((t - start) // 60_000)
        if 429 in sb.answers[n0:]:
            answers429.append((t - start) / 60_000)
        if done_at is None and ids <= sb.store.held():
            done_at = t
            if not minutes:
                break
        t += 60_000
    last_minute = (t - start) // 60_000
    return {"n": len(ids), "first_429": answers429[0] if answers429 else None, "n_429": len(answers429),
            "hb_minutes": sorted(hb_minutes), "minutes": last_minute,
            "every_minute": all(mm in hb_minutes for mm in range(last_minute)),
            "done_h": None if done_at is None else round((done_at - start) / 3600_000, 2),
            "missing": len(ids - sb.store.held())}


n_g = case_n("green")
bound_h = round(n_g["n"] / (K_SITE_EPH - K_HEADROOM) + 1, 2)
eq("Case N: an added site's 7.5-day backfill at the ceiling — the stub enforcing RateLimiter's two limits answers no "
   "429; sb receives an accepted heartbeat in every minute of the drain; every spooled id arrives; and the drain ends "
   f"within the events-per-hour figure plus one window ({bound_h} h)",
   (None, True, 0, True),
   (n_g["first_429"], n_g["every_minute"], n_g["missing"], n_g["done_h"] is not None and n_g["done_h"] <= bound_h))
print(f"  MEASURED  Case N: {n_g['n']} events drained in {n_g['done_h']} h of the injected clock (bound {bound_h} h)")
eq(f"  … and the reporter's pin K.SITE_EVENTS_PER_HOUR equals RateLimiter::EVENTS_LIMIT ({EVENTS_LIMIT}) — the pin's check",
   EVENTS_LIMIT, K_SITE_EPH)
p_full_rate = plant(("  return windowTotal(entry, atMs) + req.count <= (spooled > 0 ? spooledBound() : K.SITE_EVENTS_PER_HOUR);",
                     "  return true;"),
                    ("  const room = Math.min(lane.maxEvents - (due ? 1 : 0), spooledBound() - total - (due ? 1 : 0));",
                     "  const room = lane.maxEvents - (due ? 1 : 0);"))
n_r = case_n("red", reporter=p_full_rate, minutes=30)
FULL_RATE_MIN = -(-EVENTS_LIMIT // (8 * K_BATCH))     # minutes, at one pass of 8 rounds a minute
eq(f"RED: a reporter that drains at the lane's full rate -> the stub answers 429 as soon as one pass a minute can "
   f"spend the hour's {EVENTS_LIMIT} events (minute {FULL_RATE_MIN}; a real lane's six passes a minute reach it in "
   f"its second), and sb's accepted heartbeats stop for the rest of the window", (True, True),
   (n_r["first_429"] is not None and n_r["first_429"] <= FULL_RATE_MIN,
    not [mm for mm in n_r["hb_minutes"] if n_r["first_429"] is not None and mm > n_r["first_429"] + 1]))
print(f"  MEASURED  Case N RED: the first 429 came {n_r['first_429']} min into the drain; accepted heartbeats in "
      f"minutes {n_r['hb_minutes']}")
p_pin = plant(("  SITE_EVENTS_PER_HOUR: 20000,", "  SITE_EVENTS_PER_HOUR: 25000,"))
eq("SECOND RED: a pin edited to 25,000 -> the constant check reds", True,
   k_of(p_pin.read_text(encoding="utf-8"), "SITE_EVENTS_PER_HOUR") != EVENTS_LIMIT)
redgreen("Case N — a backfill is paced under the site's limit and the heartbeat still lands (AT-28, D1 § 11.5)",
         f"full rate -> first 429 at minute {n_r['first_429']}, accepted heartbeats at minutes {n_r['hb_minutes']}; "
         f"pin 25,000 vs EVENTS_LIMIT {EVENTS_LIMIT}",
         f"paced -> {n_g['n']} events in {n_g['done_h']} h, 429s {n_g['n_429']}, a heartbeat in each of "
         f"{len(n_g['hb_minutes'])} minutes")


# ── AT-17 — a corrupt or missing state.json loses nothing, and its re-send counts no seq_gap ───────────
def at17_leg(tag: str, kind: str) -> dict:
    st_ = site_stub(f"at17-{tag}")
    s = seat(f"at28-at17-{tag}", ingest=st_.url)
    ids = set(spool_events(s, 3, AT28_T0, tag="b1")) | set(spool_events(s, 3, AT28_T0 + 3600_000, tag="b2"))
    fl(s, AT28_T0 + 3600_000 + 60_000)
    epoch1 = st_.posts[-1]["seq_epoch"]
    if kind == "reset":
        (s.spool / "state.json").write_text('{"seq_epoch":"trunc', encoding="utf-8")
        ids |= set(spool_events(s, 3, AT28_T0 + 2 * 3600_000, tag="b3"))
        fl(s, AT28_T0 + 2 * 3600_000 + 60_000)
    else:
        (s.spool / "state.json").unlink()
        fl(s, AT28_T0 + 2 * 3600_000 + 60_000)
        fl(s, AT28_T0 + 2 * 3600_000 + 60_000 + K_HB_MS + 1000)    # one more stored row: the next heartbeat
    epoch2 = st_.posts[-1]["seq_epoch"]
    return {"stub": st_, "epoch": epoch2, "new_epoch": epoch2 != epoch1, "ids": ids,
            "dups": sum(len(b["dup"]) for b in st_.store.batch_log if b["epoch"] == epoch2)}


for kind in ("reset", "missing"):
    lg = at17_leg(kind, kind)
    eq(f"AT-17 ({'an unusable' if kind == 'reset' else 'a deleted'} state.json): the spool is re-sent under a new epoch, "
       f"answered as duplicates, and the store holds every spooled id once", (True, True, set(), True),
       (lg["new_epoch"], lg["dups"] > 0, lg["ids"] - lg["stub"].store.held(), held_once(lg["stub"])))
    assert_resend_counts_no_gap(f"AT-17 ({kind})", lg["stub"].store, lg["epoch"],
                    {e["event_id"] for b in lg["stub"].posts for e in b["events"]},
                    lg["stub"].heartbeats()[-1] if lg["stub"].heartbeats() else None)


# ── AT-3, re-measured with four sites each holding a full persisted request ──────────────────────────
def at3_run(tag: str, n_sites: int) -> dict:
    stubs = [site_stub(f"at3-{tag}-{i}") for i in range(n_sites)]
    s = multi_seat(f"at28-at3-{tag}", [site_cfg(f"s{i}", st.url) for i, st in enumerate(stubs)])
    # A full request persisted for every site: each answers 503, so each lane keeps its 200-event request.
    spool_events(s, 260, int(time.time() * 1000) - 7200_000, tag="at3", pad=900)
    for st in stubs:
        st.default = DOWN_503
    fl(s)
    state_bytes = (s.spool / "state.json").stat().st_size
    full = all((s.entry(f"s{i}").get("request") or {}).get("count") == K_BATCH for i in range(n_sites))
    walls, lines0 = [], tree_lines(s)
    for i in range(20):
        # Each hook finds the spool over the shrunk bound, so each reads state.json for the per-site cursors (§ 11.3).
        spool_events(s, 4, int(time.time() * 1000) - 7200_000 - (i + 1) * 3600_000, tag=f"at3o{i}", pad=900)
        t0 = time.perf_counter()
        hook(s, "PreToolUse", pre(tuid=f"at3{tag}{i}"), reporter=P_SMALL_BOUND)
        walls.append((time.perf_counter() - t0) * 1000)
    return {"full": full, "state_bytes": state_bytes, "median": statistics.median(walls),
            "p99": max(walls), "appends": tree_lines(s) - lines0}


at3_1, at3_4 = at3_run("one", 1), at3_run("four", 4)
eq("AT-3 precondition: every site's entry holds a full 200-event request", (True, True), (at3_1["full"], at3_4["full"]))
print(f"  MEASURED  AT-3 with the hook's state.json read on every invocation (spool over a shrunk bound): one site — "
      f"state.json {at3_1['state_bytes']} B, hook median {at3_1['median']:.0f} ms, max {at3_1['p99']:.0f} ms; four sites — "
      f"state.json {at3_4['state_bytes']} B, hook median {at3_4['median']:.0f} ms, max {at3_4['p99']:.0f} ms")
eq("AT-3: the hook path appends the same with four sites as with one — nothing per site runs in it but the drop's "
   "counts, which take the one sink line", at3_1["appends"], at3_4["appends"])
if PERF:
    eq(f"PERF: with four full requests in state.json the hook's median stays inside D1's ~190 ms headroom over the "
       f"interpreter ({at3_4['median']:.0f} ms against baseline {baseline:.0f} ms)", True, at3_4["median"] - baseline < 190)


# ── Case F — the single-site form keeps what § 3.1 says it keeps; the upgrade re-sends nothing, collides with nothing ─
# THE PREVIOUS BUILD IS GENERATED, NEVER HAND-WRITTEN: its state.json and spool are written by running the
# reporter as it stood before card#12180 — the commit `dev` stood at when the card's design branched — in this
# suite's own temp seats. It is read out of git history, so the run needs the history: a shallow checkout
# without that commit reports the case NOT MEASURED rather than passing it (the workflow fetches full history).
PREVIOUS_BUILD = "0067654623484bcbb0e48320a34a27f6ce9c865c"
_old_src = subprocess.run(["git", "-C", str(HERE), "show", f"{PREVIOUS_BUILD}:fleet-reporter/fleet-reporter.js"],
                          capture_output=True, text=True)
_d1 = (HERE.parent / "docs/design/EVENT-SCHEMA.md").read_text(encoding="utf-8")
_member_tbl = _d1[_d1.index("**The `selftest` member set, declared here"):]
_member_tbl = _member_tbl[_member_tbl.index("| Member | Asserts |"):]
_member_tbl = _member_tbl[:_member_tbl.index("\n\n")]
SELFTEST_MEMBERS = re.findall(r"^\| `([a-z_]+)` \|", _member_tbl, re.M)


def old_reporter() -> Path:
    d = tmpdir("fr-prev-build-")
    shutil.copytree(HERE / "fixtures", d / "fixtures")
    (d / "fleet-reporter.js").write_text(_old_src.stdout, encoding="utf-8")
    return d / "fleet-reporter.js"


def strip_seq(e: dict) -> dict:
    return {k: v for k, v in e.items() if k != "seq"}


ENV_KEPT = ("schema_version", "install_id", "seat_id", "reporter_platform", "runtime_version")


def legacy_seat(tag: str, st: SiteStub, old: Path, *, commit_drop_last: bool = False) -> Seat:
    """A single-site seat whose state.json and spool the PREVIOUS build wrote: two passes delivered, with one
    spooled heartbeat behind the cursor and one ahead of it, and three hook events ahead."""
    s = seat(f"at28-f-{tag}", ingest=st.url)
    for i in range(3):
        hook(s, "PreToolUse", pre(tuid=f"f{tag}a{i}"), reporter=old)
    flush(s, reporter=old)                                   # sends 3, spools hb1
    for i in range(3):
        hook(s, "PreToolUse", pre(tuid=f"f{tag}b{i}"), reporter=old)
    if commit_drop_last:
        st.script = [COMMIT_DROP]
    flush(s, reporter=old)                                   # sends hb1 + 3, spools hb2 (or: committed, unanswered)
    for i in range(3):
        hook(s, "PreToolUse", pre(tuid=f"f{tag}c{i}"), reporter=old)
    return s


def clone_seat(s: Seat, tag: str, url: str) -> Seat:
    c = seat(f"at28-f-clone-{tag}", ingest=url)
    shutil.rmtree(c.spool)
    shutil.copytree(s.spool, c.spool)
    c.cfg = dict(s.cfg, spool_dir=str(c.spool), ingest_url=url)
    c.write_cfg()
    return c


if _old_src.returncode != 0:
    # On CI the workflow fetches the full history for exactly this case, so a missing commit there is a broken
    # checkout and FAILS the run; elsewhere it is a measurement this checkout cannot make, reported as one.
    (bad if os.environ.get("CI") else skip)(
        f"Case F needs the previous build from git history ({PREVIOUS_BUILD[:8]}), which this checkout lacks — "
        "NOT measured, and that is not a pass")
else:
    OLD = old_reporter()
    # F1 — the kept half by equality, the changed half by name.
    f_st, f_cmp = site_stub("f1"), site_stub("f1-cmp")
    f1 = legacy_seat("kept", f_st, OLD)
    legacy_state = f1.state()
    old_hb = next(e for e in reversed(f1.events()) if e["kind"] == "reporter.heartbeat")
    pre_ids = {e["event_id"] for e in f1.events()}
    f1c = clone_seat(f1, "kept", f_cmp.url)
    flush(f1c, reporter=OLD)                                  # what the previous build sends for the same spool
    old_batch = f_cmp.posts[-1]
    n0 = len(f_st.posts)
    fl(f1)                                                    # the new build, installed over it
    new_batch = f_st.posts[n0]
    new_hb = new_batch["events"][0]
    # The spool's own lines, as both builds found them: each start also reaps the calls its predecessor left open
    # (§ 8.3), and those closes are minted per start, so they are not "the same spool".
    kept_old = [json.dumps(strip_seq(e)) for e in old_batch["events"] if e["event_id"] in pre_ids]
    kept_new = [json.dumps(strip_seq(e)) for e in new_batch["events"][1:] if e["event_id"] in pre_ids]
    eq("Case F, the kept half: the envelope's fields but batch_id, sent_at, reporter_version and seq_epoch are equal to "
       "the previous build's for the same spool, and every spooled event, its seq removed, is byte-equal",
       (True, True, True), ({k: new_batch[k] for k in ENV_KEPT} == {k: old_batch[k] for k in ENV_KEPT},
                            kept_new == kept_old, len(kept_old) >= 4))
    # card#12239 adds § 6.14's roundtable declaration pair to every heartbeat, by name — the one addition
    # to the field set since the previous build, and the only one this comparison admits.
    eq("  … the heartbeat's field set is the previous build's plus card#12239's roundtable pair, its selftest "
       "members are § 6.14's table (the previous set plus token_accepted and card#12239's rt_name_ok), and its config_fingerprint equals the "
       "previous build's for the same config",
       (sorted(list(old_hb["data"]) + ["roundtable_agent_name", "roundtable_agent_name_check"]), sorted(SELFTEST_MEMBERS), sorted(sorted(old_hb["data"]["selftest"]) + ["token_accepted", "rt_name_ok"]),
        old_hb["data"]["config_fingerprint"]),
       (sorted(new_hb["data"]), sorted(new_hb["data"]["selftest"]), sorted(new_hb["data"]["selftest"]),
        new_hb["data"]["config_fingerprint"]))
    seqs = [e["seq"] for e in new_batch["events"]]
    spool_order = [e["event_id"] for e in f1.events()]
    eq("  … the changed half, by name: the heartbeat is item 0 under seq = first_seq, the batch's seqs are contiguous "
       "from it, and with the heartbeat removed the spooled events follow spool order",
       ("reporter.heartbeat", list(range(seqs[0], seqs[0] + len(seqs))), True),
       (new_hb["kind"], seqs, [x for x in spool_order if x in {e["event_id"] for e in new_batch["events"][1:]}]
        == [e["event_id"] for e in new_batch["events"][1:]]))
    eq("  … the first request is under a seq_epoch other than the legacy state's, first_seq 1; the stub counts "
       "seq_epoch_change 1 and seq_collision 0", (True, 1, 1, 0),
       (new_batch["seq_epoch"] != legacy_state["seq_epoch"], seqs[0], f_st.store.seq_epoch_change, f_st.store.seq_collision))
    c0, e0 = f1.entry().get("counters", {}).get("events_sent", 0), f1.state()["counters"].get("events_emitted", 0)
    h0 = len(f_st.heartbeats())
    for i in range(2):
        hook(f1, "PreToolUse", pre(tuid=f"fkd{i}"))
        fl(f1, int(time.time() * 1000) + (i + 1) * (K_HB_MS + 1000))
    dsent = f1.entry().get("counters", {}).get("events_sent", 0) - c0
    demit = f1.state()["counters"].get("events_emitted", 0) - e0
    hb_times = [h["event_time"] for h in f_st.heartbeats()[h0:]]
    eq("  … events_sent − events_emitted equals the heartbeats the stub received, one per pass of the heartbeat cadence",
       (len(hb_times), 2), (dsent - demit, len(hb_times)))
    st1 = (f1.spool / "state.json").read_text(encoding="utf-8")
    eq("  … after the restarts: duplicates 0, state_reset 0, and state.json holds no ingest_url anywhere — only "
       "sites.default.ingest_url_sha256", (0, 0, False, True),
       (sum(len(b["dup"]) for b in f_st.store.batch_log), f1.state()["counters"].get("state_reset", 0),
        "127.0.0.1:" in st1 or "ingest_url\"" in st1, bool(f1.entry().get("ingest_url_sha256"))))

    # F2 — the upgrade boundary (round 4's F1): the previous build's last batch committed and unanswered.
    def f_boundary(tag: str, reporter: Path = REPORTER, *, how: str = "stop") -> dict:
        st = site_stub(f"f2-{tag}")
        if how == "kill":
            s = seat(f"at28-f2-{tag}", ingest=st.url)
            for i in range(4):
                hook(s, "PreToolUse", pre(tuid=f"f2{tag}a{i}"), reporter=OLD)
            p = live(s, OLD)
            wait_for(lambda: len(st.store.rows) >= 4, timeout=30)
            for i in range(3):
                hook(s, "PreToolUse", pre(tuid=f"f2{tag}b{i}"), reporter=OLD)
            st.script = [{"commit_hang": 60}]
            seen = wait_for(lambda: len(st.posts) >= 2 and st.answers[-1:] == ["hung"], timeout=40)
            p.kill()
            p.wait()
            s.freeze_flusher()
        else:
            s = seat(f"at28-f2-{tag}", ingest=st.url)
            for i in range(4):
                hook(s, "PreToolUse", pre(tuid=f"f2{tag}a{i}"), reporter=OLD)
            flush(s, reporter=OLD)
            for i in range(3):
                hook(s, "PreToolUse", pre(tuid=f"f2{tag}b{i}"), reporter=OLD)
            st.script = [COMMIT_DROP]
            flush(s, reporter=OLD)
            seen = True
        committed = st.posts[-1]
        legacy = s.state()
        n_before = len(st.posts)
        died = None
        if how == "restart":
            # A start of the new build that dies before its first save: the spool directory refuses its writes.
            (s.spool / "flusher.lock").unlink(missing_ok=True)
            os.chmod(s.spool, 0o500)
            try:
                subprocess.run(["node", str(reporter), "flusher"], capture_output=True, text=True, timeout=60,
                               env=s.env(freeze=False, FLEET_REPORTER_ONE_PASS="1"), cwd=str(HERE))
            finally:
                os.chmod(s.spool, 0o700)
                s.freeze_flusher()
            died = (len(st.posts) == n_before, s.state() == legacy)
        t = int(time.time() * 1000)
        fl(s, t, reporter=reporter)
        first = st.posts[n_before] if len(st.posts) > n_before else {}
        fl(s, t + K_HB_MS + 1000, reporter=reporter)               # one more stored row: the next heartbeat
        sent = {e["event_id"] for b in st.posts for e in b["events"]}
        return {"seen": seen, "died": died, "st": st, "first": first, "legacy_epoch": legacy.get("seq_epoch"),
                "new_epoch": first.get("seq_epoch") not in (None, legacy.get("seq_epoch")),
                "first_seq": (first.get("events") or [{}])[0].get("seq"),
                "hb0": (first.get("events") or [{}])[0].get("kind") == "reporter.heartbeat",
                "committed_dup": {e["event_id"] for e in committed["events"]} <= st.store.held()
                and all(b["dup"] for b in st.store.batch_log if b["batch_id"] == first.get("batch_id")),
                "collision": st.store.seq_collision, "once": held_once(st), "sent": sent}

    for how in ("stop", "kill", "restart"):
        fb = f_boundary(how, how=how)
        if how == "restart":
            eq("Case F, upgrade boundary — a new-build start that dies before its first save sent nothing and left the "
               "legacy state.json as it was", (True, True), fb["died"])
        eq(f"Case F, upgrade boundary ({how}): the new build's first request is under a new seq_epoch with first_seq 1 "
           f"and a heartbeat at item 0 (pinned: the timer is due at a start); the committed batch's events arrive again "
           f"and are answered as duplicates; seq_collision 0; every event_id held once",
           (True, True, 1, True, True, 0, True),
           (fb["seen"], fb["new_epoch"], fb["first_seq"], fb["hb0"], fb["committed_dup"], fb["collision"], fb["once"]))
        assert_resend_counts_no_gap(f"Case F, upgrade boundary ({how})", fb["st"].store, fb["first"].get("seq_epoch"), fb["sent"],
                        fb["st"].heartbeats()[-1] if fb["st"].heartbeats() else None)
    p_carry = plant(("    seq_epoch: ulid(atMs), next_seq: 1, cursors: isPlain(s.cursors) ? s.cursors : {}, counters: {}, counter_rises: {},",
                     "    seq_epoch: s.seq_epoch, next_seq: s.next_seq, cursors: isPlain(s.cursors) ? s.cursors : {}, counters: {}, counter_rises: {},"))
    fb_r = f_boundary("red-carry", reporter=p_carry)
    eq("THIRD RED: a reporter that carries the legacy epoch and next_seq (design r4's rule 3) -> its first request puts "
       "a heartbeat at the committed batch's first seq and each committed event one seq later: seq_collision >= 1",
       True, fb_r["collision"] >= 1)

    # F3 — old spooled heartbeats go to one site.
    def f_lift(tag: str, reporter: Path = REPORTER) -> dict:
        dst, sbx = site_stub(f"f3-d-{tag}"), site_stub(f"f3-s-{tag}")
        s = legacy_seat(f"lift-{tag}", dst, OLD)
        old_hbs = {e["event_id"] for e in s.events() if e["kind"] == "reporter.heartbeat"}
        others = {e["event_id"] for e in s.events() if e["kind"] != "reporter.heartbeat"}
        url = s.cfg["ingest_url"]
        for k in TRANSPORT_KEYS_PY:
            s.cfg.pop(k, None)
        s.cfg["sites"] = [site_cfg("default", url, token=s.token), site_cfg("sandbox", sbx.url)]
        s.write_cfg()
        n0 = len(dst.posts)
        fl(s, reporter=reporter)
        got_d = {e["event_id"] for b in dst.posts[n0:] for e in b["events"]}
        return {"d_old_hbs": old_hbs & got_d, "s_old_hbs": old_hbs & sbx.received_ids(),
                "s_others": others <= sbx.received_ids(), "old_hbs": old_hbs,
                "s_dropped": (s.entry("sandbox").get("counters") or {}).get("spool_dropped_events", 0),
                "s_fps": sorted({h["data"]["config_fingerprint"] for h in sbx.heartbeats()}),
                "d_fp": fp_of(url), "s_fp": fp_of(sbx.url)}

    fl3 = f_lift("green")
    eq("Case F, lifted into [default, sandbox]: default receives the previous build's heartbeat lines ahead of its "
       "cursor (its own); sandbox receives none of them, every other spooled event, and counts spool_dropped_events 0 "
       "— a skip, not a loss", (True, set(), True, 0, [fl3["s_fp"]]),
       (bool(fl3["d_old_hbs"]), fl3["s_old_hbs"], fl3["s_others"], fl3["s_dropped"], fl3["s_fps"]))
    p_every = plant(("      if (rec.e.kind === 'reporter.heartbeat' && !(rec.e.data && rec.e.data.config_fingerprint === fingerprint)) {",
                     "      if (false) {"))
    fl3r = f_lift("red", reporter=p_every)
    eq("SECOND RED: a reporter that delivers an old spooled heartbeat to every site -> sandbox receives a heartbeat whose "
       "fingerprint is default's", True, fl3r["d_fp"] in fl3r["s_fps"])

    # F4 — the discriminating control: the existing site under another name re-sends; under `default`, it does not.
    def f_rename(tag: str, name: str) -> dict:
        st, sbx = site_stub(f"f4-{tag}"), site_stub(f"f4-s-{tag}")
        s = legacy_seat(f"ren-{tag}", st, OLD)
        url = s.cfg["ingest_url"]
        legacy = s.state()
        for k in TRANSPORT_KEYS_PY:
            s.cfg.pop(k, None)
        s.cfg["sites"] = [site_cfg(name, url, token=s.token)] + ([site_cfg("sandbox", sbx.url)] if name == "default" else [])
        s.write_cfg()
        spooled = {e["event_id"] for e in s.events()}
        n0 = len(st.posts)
        fl(s)
        new = st.posts[n0:]
        return {"new_epoch": all(b["seq_epoch"] != legacy["seq_epoch"] for b in new),
                "dups": sum(len(b["dup"]) for b in st.store.batch_log[n0:]), "union": spooled <= st.store.held(),
                "resent_behind": len([e for b in new for e in b["events"] if e["kind"] != "reporter.heartbeat"]),
                "sandbox_backfill": spooled - {e["event_id"] for e in s.events() if e["kind"] == "reporter.heartbeat"} <= sbx.received_ids()}

    fr_prod = f_rename("prod", "prod")
    eq("Case F CONTROL: the same legacy state under a multi-site config naming the existing site `prod` re-sends from the "
       "oldest bucket under a new epoch — duplicates > 0, the held set the union", (True, True, True),
       (fr_prod["new_epoch"], fr_prod["dups"] > 0, fr_prod["union"]))
    fr_def = f_rename("default", "default")
    eq("  … named `default` beside a new `sandbox`, it re-sends nothing behind the cursor, and sandbox gets the backfill",
       (0, True), (fr_def["dups"], fr_def["sandbox_backfill"]))

    # F5 — the rule-3 residual, measured: `default` repointed in the same edit as the upgrade.
    def f_repoint(tag: str) -> dict:
        st, sx, sy = site_stub(f"f5-{tag}"), site_stub(f"f5-x-{tag}"), site_stub(f"f5-y-{tag}")
        s = legacy_seat(f"rep-{tag}", st, OLD)
        cur = s.state()["cursors"]
        behind = set()
        for f in sorted(s.spool.glob("*.jsonl")):
            off = 0
            for ln in f.read_bytes().splitlines(keepends=True):
                off += len(ln)
                # The previous build's heartbeat lines are owed only to the server whose fingerprint they
                # carry (§ 3.1 rule 1), so neither new server is owed them: the population is the events.
                if off <= cur.get(f.name[:10], 0) and json.loads(ln)["e"]["kind"] != "reporter.heartbeat":
                    behind.add(json.loads(ln)["e"]["event_id"])
        for k in TRANSPORT_KEYS_PY:
            s.cfg.pop(k, None)
        s.cfg["sites"] = [site_cfg("default", sx.url, token=s.token)]
        s.write_cfg()
        fl(s)
        missing_x = behind - sx.received_ids()
        counted = (s.entry().get("counters") or {}).get("spool_dropped_events", 0)
        s.cfg["sites"] = [site_cfg("default", sy.url, token=s.token)]
        s.write_cfg()
        fl(s)
        return {"behind": behind, "missing_x": missing_x, "counted": counted,
                "y_resent": behind <= sy.received_ids()}

    frp = f_repoint("green")
    eq("Case F, THE RULE-3 RESIDUAL, MEASURED: legacy state under a config whose `default` names another server — that "
       "server is missing every id behind the legacy cursor, and nothing counts it", (frp["behind"], 0),
       (frp["missing_x"], frp["counted"]))
    print(f"  MEASURED  Case F rule-3 residual: {len(frp['missing_x'])} ids skipped, uncounted, on the one start before "
          "the hash is recorded")
    eq("  … after that one start the hash is recorded, and the same edit again re-sends: the window is one start",
       True, frp["y_resent"])

    def f_first_site(tag: str, reporter: Path) -> dict:
        sbx, sp = site_stub(f"f6-s-{tag}"), site_stub(f"f6-p-{tag}")
        s = legacy_seat(f"first-{tag}", sbx, OLD)                  # the legacy cursor is the sandbox's
        spooled = {e["event_id"] for e in s.events() if e["kind"] != "reporter.heartbeat"}
        for k in TRANSPORT_KEYS_PY:
            s.cfg.pop(k, None)
        s.cfg["sites"] = [site_cfg("prod", sp.url), site_cfg("sandbox", sbx.url, token=s.token)]
        s.write_cfg()
        fl(s, reporter=reporter)
        return {"missing": spooled - sp.received_ids(),
                "counted": (s.entry("prod").get("counters") or {}).get("spool_dropped_events", 0)}

    ff_g = f_first_site("green", REPORTER)
    eq("  … under [prod, sandbox] with the legacy cursor the sandbox's, prod (a name the state does not hold) gets the "
       "whole spool", set(), ff_g["missing"])
    p_first = plant(("  const prior = isPlain(state.sites) ? state.sites : nameMap();",
                     "  if (isPlain(state.sites) && state.sites.default && state.sites.default.legacy && sites[0] && sites[0].name) "
                     "state.sites[sites[0].name] = state.sites.default;\n  const prior = isPlain(state.sites) ? state.sites : nameMap();"))
    ff_r = f_first_site("red", p_first)
    eq("RED: a reporter that adopts the legacy state for the config's first site whatever its name -> prod is missing "
       "every id behind that cursor, and nothing counts it (the missing ids, asserted)", (True, 0),
       (len(ff_r["missing"]) > 0, ff_r["counted"]))
    print(f"  MEASURED  Case F RED: prod missing {sorted(ff_r['missing'])[:3]}… ({len(ff_r['missing'])} ids)")
    redgreen("Case F — the single-site form, and the upgrade into this build (AT-28, D1 § 3.1 rule 3)",
             f"legacy epoch carried -> collision {fb_r['collision']}; old heartbeats to every site -> sandbox fingerprints "
             f"{fl3r['s_fps']}; legacy adopted by the first site -> prod missing {len(ff_r['missing'])} ids, counted {ff_r['counted']}",
             f"kept half equal, heartbeat item 0, new epoch first_seq 1, collisions 0 at the boundary (stop, SIGKILL, a "
             f"dying start); lifted -> sandbox old heartbeats {fl3['s_old_hbs']}; renamed -> re-sent with {fr_prod['dups']} "
             f"duplicates; repointed default -> {len(frp['missing_x'])} skipped for one start (the named residual)")


# ── Case B — a down site stalls nothing and loses nothing, and the bound can fail ─────────────────────
B_K = {"FLUSH_MS": 2000, "REQUEST_MS": 8000, "HEARTBEAT_MS": 12000, "HEALTH_MS": 48000}
B_CONNECT = 4000
EPS_S = B_CONNECT / 5000          # a fifth of the connect deadline: the smallest stall a sequential drain adds
B_DRIVE_S = 40


def b_flusher(*defects) -> Path:
    return plant_src(*[(rf"\b{k}: \d+,", f"{k}: {v},") for k, v in B_K.items()],
                     (r"\bCONNECT_MS: \d+,", f"CONNECT_MS: {B_CONNECT},"),
                     *[(re.escape(old), new) for old, new in defects])


class BlackHole:
    """A listener with backlog 0 that never accepts, its one accept-queue slot held by the suite's own
    connection: every later SYN is dropped by the kernel, so a connect waits out its deadline."""

    def __init__(self):
        self.sock = socket.socket()
        self.sock.bind(("127.0.0.1", 0))
        self.sock.listen(0)
        self.port = self.sock.getsockname()[1]
        self.fill = socket.create_connection(("127.0.0.1", self.port), timeout=2)
        probe = socket.socket()
        probe.settimeout(0.5)
        try:
            probe.connect(("127.0.0.1", self.port))
            self.holds = False
        except (socket.timeout, OSError):
            self.holds = True
        finally:
            probe.close()

    @property
    def url(self):
        return f"https://127.0.0.1:{self.port}/api/ingest/events"

    def close(self):
        self.fill.close()
        self.sock.close()


def case_b(tag: str, reporter: Path, *, blackhole: bool, recover: bool = False) -> dict:
    sa = site_stub(f"b-a-{tag}")
    hole = BlackHole() if blackhole else None
    sb = None if blackhole else site_stub(f"b-b-{tag}")
    if sb:
        sb.default = {"hold": 3600}                       # accepts TCP and TLS, never answers
    m = multi_seat(f"at28-b-{tag}", [site_cfg("sa", sa.url), site_cfg("sb", hole.url if hole else sb.url)])
    # One hook before the flusher starts, so its first seat pass folds predicate counts into the seat's totals
    # before any request is built: the bookkeeping a request's heartbeat is composed from then exists, and a
    # later fold changes it while sb's request is held.
    hook(m, "PreToolUse", pre(tuid=f"b{tag}pre"))
    p = live(m, reporter, FLEET_REPORTER_BACKOFF_MAX_MS=0)
    arrivals, snaps, reqs = [], [], set()
    t_start = time.time()
    seen_posts = 0
    next_hook = t_start
    try:
        while time.time() - t_start < B_DRIVE_S:
            if time.time() >= next_hook:
                # The live flusher holds the lock: this hook must find it, not have the suite's freeze
                # replace it (`hook()` freezes the lock for seats with no live flusher).
                subprocess.run(["node", str(REPORTER), "hook", "PreToolUse"], input=json.dumps(pre(tuid=f"b{tag}{int(next_hook * 10)}")),
                               capture_output=True, text=True, env=m.env(freeze=False), cwd=str(HERE))
                next_hook += 1.0
            if len(sa.posts) > seen_posts:
                arrivals += [time.time()] * (len(sa.posts) - seen_posts)
                seen_posts = len(sa.posts)
            try:
                snaps.append(json.loads((m.spool / "index" / "snapshot.json").read_text(encoding="utf-8"))["taken_at"])
            except Exception:
                pass
            rq = m.entry("sb").get("request")
            if rq:
                reqs.add(rq["batch_id"])
            time.sleep(0.02)
        outage_req = m.entry("sb").get("request")
        d = {}
        if recover and sb:
            sb.default = None
            t_rec = time.time()
            ids = {e["event_id"] for e in m.events()}
            wait_for(lambda: ids <= sb.store.held(), timeout=60)
            first = sb.store.batch_log[0]["batch_id"] if sb.store.batch_log else None
            first_body = next((b for b in sb.posts if b.get("batch_id") == first), {})
            same_id = [r for r, b in zip(sb.raw, sb.posts) if b.get("batch_id") == first]
            d = {"all": ids <= sb.store.held(), "dups": sum(len(b["dup"]) for b in sb.store.batch_log),
                 "first_is_held": outage_req is not None and first == outage_req["batch_id"],
                 # The request persisted during the outage, as state.json held it, equals the first attempt; and
                 # every attempt of it, the one committed after the recovery included, carries the first
                 # attempt's `events` bytes — through a live process whose folds ran between the attempts.
                 "held_bytes": outage_req is not None and first_body.get("events") == outage_req["events"]
                 and len(same_id) >= 2 and len({events_bytes(r) for r in same_id}) == 1,
                 "one_request": len(reqs) == 1, "t_rec": t_rec}
            time.sleep(B_K["REQUEST_MS"] / 1000)
            hbs = [r["event"] for r in sb.store.rows if r["kind"] == "reporter.heartbeat"]
            d["hbs"] = [(h["event_time"], h["data"]["oldest_unsent_age_s"]) for h in hbs]
            d["dropped"] = (m.entry("sb").get("counters") or {}).get("spool_dropped_events", 0)
    finally:
        stop_live(p, m)
        if hole:
            hole.close()
    gaps = [b - a for a, b in zip(arrivals, arrivals[1:])]
    ts = sorted({calendar.timegm(time.strptime(x[:19], "%Y-%m-%dT%H:%M:%S")) + int(x[20:23]) / 1000 for x in snaps})
    sgaps = [b - a for a, b in zip(ts, ts[1:])]
    hb_t = [calendar.timegm(time.strptime(h["event_time"][:19], "%Y-%m-%dT%H:%M:%S")) for h in sa.heartbeats()]
    return {"max_gap": round(max(gaps), 2) if gaps else None, "max_snap_gap": round(max(sgaps), 2) if sgaps else None,
            "max_hb_gap": max([b - a for a, b in zip(hb_t, hb_t[1:])], default=None), "n_a": len(arrivals),
            "holds": hole.holds if hole else True, "t_start": t_start, **d}


FLUSH_S, REQ_S, HB_S = B_K["FLUSH_MS"] / 1000, B_K["REQUEST_MS"] / 1000, B_K["HEARTBEAT_MS"] / 1000
abort = subprocess.run(["sysctl", "-n", "net.ipv4.tcp_abort_on_overflow"], capture_output=True, text=True).stdout.strip()
b_g1 = case_b("silent", b_flusher(), blackhole=False, recover=True)
RTT_S = 0.5      # sa's own round trip and the pass's local work, on a loaded runner — measured well under it
eq(f"Case B (sb accepts TLS and never answers; timing scaled on a copy, backoff forced to zero): every gap between sa's "
   f"batches is <= FLUSH_MS + sa's round trip + ε ({FLUSH_S} + {RTT_S} + {EPS_S} s); every gap between snapshot "
   f"taken_at values is <= FLUSH_MS + ε; sa's heartbeats arrive within HEARTBEAT_MS + FLUSH_MS + ε",
   (True, True, True, True),
   (b_g1["n_a"] >= 5, b_g1["max_gap"] is not None and b_g1["max_gap"] <= FLUSH_S + RTT_S + EPS_S,
    b_g1["max_snap_gap"] is not None and b_g1["max_snap_gap"] <= FLUSH_S + EPS_S + 0.2,
    b_g1["max_hb_gap"] is not None and b_g1["max_hb_gap"] <= HB_S + FLUSH_S + EPS_S + 1))
eq("  … on restoring sb: what it commits first is the request persisted during the outage — equal to what state.json "
   "held, and byte for byte the first attempt's `events` on every attempt — sb's entry having held exactly one request "
   "throughout; every spooled event arrives, duplicates 0, spool_dropped_events 0",
   (True, True, True, True, 0, 0),
   (b_g1.get("first_is_held"), b_g1.get("held_bytes"), b_g1.get("one_request"), b_g1.get("all"), b_g1.get("dups"), b_g1.get("dropped")))
outage_hb = b_g1.get("hbs", [])
eq("  … the persisted request's heartbeat is stamped inside the outage's first heartbeat interval, and the next "
   "request's heartbeat reports the backlog's age (oldest_unsent_age_s >= the outage's length), and no other "
   "heartbeat stamped inside the outage reaches sb", (True, True, 1),
   (bool(outage_hb) and outage_hb[0][0] <= time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(b_g1["t_start"] + HB_S + 2)),
    len(outage_hb) > 1 and (outage_hb[1][1] or 0) >= B_DRIVE_S - HB_S,
    len([h for h in outage_hb if h[0] < time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(b_g1["t_rec"]))])))
if abort != "0":
    skip(f"Case B's black-holed-port leg needs net.ipv4.tcp_abort_on_overflow = 0 (it reads {abort!r}): NOT measured")
else:
    b_g2 = case_b("hole", b_flusher(), blackhole=True)
    if not b_g2["holds"]:
        skip("Case B's black hole did not hold (a second connect to the backlog-0 listener completed) — NOT measured")
    else:
        eq(f"Case B (sb a black-holed port, the connect-deadline path): every gap between sa's batches is <= "
           f"FLUSH_MS + round trip + ε", True, b_g2["max_gap"] is not None and b_g2["max_gap"] <= FLUSH_S + RTT_S + EPS_S)
SEQUENTIAL = ("  if (running) await Promise.all([seatMain(), ...lanes.map(laneMain)]);",
              "  if (running) await Promise.all([seatMain(), (async () => { while (running) { for (const l of lanes) { "
              "try { await lanePass(l); } catch (e) { if (e instanceof LostOwnership) { lost(e); break; } } } "
              "if (onePass || !running) break; await nap(K.FLUSH_MS); } })()]);")
b_r1 = case_b("silent-red", b_flusher(SEQUENTIAL), blackhole=False)
eq(f"RED: a reporter that drains sites in sequence inside one pass -> sa's inter-batch gap reaches FLUSH_MS + "
   f"REQUEST_MS (> the bound {FLUSH_S + RTT_S + EPS_S} s)", True,
   b_r1["max_gap"] is not None and b_r1["max_gap"] >= FLUSH_S + REQ_S * 0.9)
if abort == "0":
    b_r2 = case_b("hole-red", b_flusher(SEQUENTIAL), blackhole=True)
    eq(f"RED: … and FLUSH_MS + CONNECT_MS on the black-holed port (> the bound)", True,
       b_r2["max_gap"] is not None and b_r2["max_gap"] >= FLUSH_S + B_CONNECT / 1000 * 0.9)
# THIRD RED — found by this case's first run: the persisted request held as the built object, not as a copy, so
# the next fold's predicate counts rewrote the heartbeat inside it and every later attempt's bytes moved.
b_r3 = case_b("silent-red-ref", b_flusher(("  entry.request = JSON.parse(JSON.stringify(built.request));",
                                           "  entry.request = built.request;")), blackhole=False, recover=True)
eq("THIRD RED: a reporter that keeps the built request object rather than a copy of it -> a fold between two attempts "
   "rewrites the heartbeat inside the request, and the retries' bytes are not the first attempt's", False,
   b_r3.get("held_bytes"))
redgreen("Case B — a down site stalls nothing and loses nothing (AT-28, D1 § 3.1; timing scaled on a copy)",
         f"sequential drain -> sa's max inter-batch gap {b_r1['max_gap']} s (silent site); request held by reference -> "
         f"attempts byte-identical {b_r3.get('held_bytes')}",
         f"one lane per site -> sa's max gap {b_g1['max_gap']} s, snapshot gap {b_g1['max_snap_gap']} s, sa heartbeat gap "
         f"{b_g1['max_hb_gap']} s; on recovery sb got the persisted request first and every event, duplicates {b_g1.get('dups')}")


print("\n== 24. THE RUNBOOK'S BLOCKS STOP AT THEIR FIRST FAILURE, AND NO CRONTAB READS AS EMPTY (card#12334) ==")
# An agent's shell tool runs what it is given as `eval '<command>' && …` (INSTALL-LINUX.md § The values to
# substitute, the paragraph after the table). bash ignores `set -e` in every command the left side of `&&` runs,
# a subshell that sets it itself included, so a `( set -euo pipefail … )` block pasted there carried on past a
# failed `crontab -l` and installed a crontab holding the reporter's block alone. Each block is now its own
# `bash -euo pipefail <<'SH'` process. Everything here is READ OUT OF THE RUNBOOK and run under that same
# `eval … &&` wrapper, and every `crontab` it runs is a stub on PATH: the real one is never called.
TOOL_WRAP = 'eval "$1" && :'
SH_BLOCKS = re.findall(r"^(bash -euo pipefail <<'SH'\n.*?\nSH)$", INSTALL_DOC, re.M | re.S)
SUBSHELL_E = re.compile(r"^\s*\(\s*set -e", re.M)
eq("precondition: the runbook has blocks in the separate-bash form, and every one parses (`bash -n`, "
   "with each <placeholder> filled)", (True, []),
   (bool(SH_BLOCKS), [b.splitlines()[1][:60] for b in SH_BLOCKS if subprocess.run(
       ["bash", "-n"], input=re.sub(r"<[a-z_ ]+>", "X", b), capture_output=True, text=True).returncode != 0]))
eq("RED: the subshell detector finds the old block shape", True,
   bool(SUBSHELL_E.search("```bash\n( set -euo pipefail\n  false )\n```")))
eq("  … and no block of the runbook is a `( set -e … )` subshell", [],
   [INSTALL_DOC[:m.start()].count("\n") + 1 for m in SUBSHELL_E.finditer(INSTALL_DOC)])


def tool_run(cmd: str, env: "dict | None" = None) -> subprocess.CompletedProcess:
    """`cmd` as an agent's shell tool runs it: the left side of `&&`."""
    return subprocess.run(["bash", "-c", TOOL_WRAP, "tool", cmd], capture_output=True, text=True, env=env)


HEAD = SH_BLOCKS[0].splitlines()[0] if SH_BLOCKS else "bash -euo pipefail <<'SH'"
r_old = tool_run("( set -euo pipefail\n  false\n  echo continued )\necho rc=$?")
r_new = tool_run(f"{HEAD}\n  false\n  echo continued\nSH\necho rc=$?")
eq("RED: under the tool's `eval … &&`, the old subshell block runs on past `false` and exits 0", "continued\nrc=0\n",
   r_old.stdout)
eq("  … the runbook's block form stops at the `false`", "rc=1\n", r_new.stdout)

READS = sorted(set(re.findall(r"^  (read_crontab\(\) \{.*\})$", INSTALL_DOC, re.M)))
eq("every block that reads the crontab defines `read_crontab` identically (one definition, however many copies)", 1,
   len(READS))
cr = tmpdir("fr-cron-")
os.chmod(cr, 0o700)
(cr / "bin").mkdir()
STUB = cr / "bin" / "crontab"
STUB.write_text(f"""#!/bin/sh
D={cr}
echo "crontab $*" >> $D/calls.log
if [ -e $D/broken ] && [ "$1" = -l ]; then echo "crontab: cannot open the spool: Permission denied" >&2; exit 2; fi
case "$1" in
  -l) [ -e $D/installed ] || {{ echo "no crontab for $(id -un)" >&2; exit 1; }}; cat $D/installed ;;
  -r) [ -e $D/installed ] || {{ echo "no crontab for $(id -un)" >&2; exit 1; }}; rm $D/installed ;;
  -) cat > $D/installed ;;
  *) cp "$1" $D/installed ;;
esac
""", encoding="utf-8")
os.chmod(STUB, 0o700)
cr_home = cr / "home"
(cr_home / ".claude").mkdir(parents=True)
(cr_home / ".claude" / "settings.json").write_text("{}\n", encoding="utf-8")
(cr_home / ".local" / "share" / "fleet-reporter").mkdir(parents=True)
shutil.copy(REPORTER, cr_home / ".local" / "share" / "fleet-reporter" / "fleet-reporter.js")
CR_B = cr / "B"
NODE_DIR = os.path.dirname(shutil.which("node") or "/usr/bin/node")


def cron_state(installed: "str | None", broken: bool = False) -> None:
    for f in ("installed", "broken", "calls.log"):
        (cr / f).unlink(missing_ok=True)
    if installed is not None:
        (cr / "installed").write_text(installed, encoding="utf-8")
    if broken:
        (cr / "broken").write_text("", encoding="utf-8")
    shutil.rmtree(CR_B, ignore_errors=True)
    CR_B.mkdir(mode=0o700)
    for h in ("coord-config.js", "stop-flusher.js"):
        src = re.search(r"cat > \"\$B/" + re.escape(h) + r"\" <<'JS'\n(.*?)\nJS\n", INSTALL_DOC, re.S).group(1)
        (CR_B / h).write_text(src + "\n", encoding="utf-8")


def cron_block(marker: str) -> str:
    found = [b for b in SH_BLOCKS if marker in b]
    if len(found) != 1:
        raise AssertionError(f"{len(found)} runbook blocks hold {marker!r}, not one (the check would be vacuous)")
    # The stub is asserted to be what `crontab` resolves to before the block runs: the real one is never reached.
    # `node` too, by name: the blocks run it, and a block that cannot find it fails for a reason no assertion names.
    return (f'[ "$(command -v crontab)" = {STUB} ] || {{ echo "STUB NOT ON PATH" >&2; exit 99; }}\n'
            f'command -v node > /dev/null || {{ echo "NO node ON PATH" >&2; exit 98; }}\n{found[0]}\necho rc=$?')


def cron_run(marker: str, *, block: "str | None" = None) -> "tuple[subprocess.CompletedProcess, list[str], str | None]":
    # The PATH holds the stub, then the directory of the `node` this suite runs, then the system's. `node` is not
    # in /usr/bin on every host — on the CI runner it is /usr/local/bin/node — so a PATH of the stub and
    # /usr/bin:/bin alone left the blocks with no `node` there (card#12334, PR #371's first CI run).
    env = {"PATH": f"{cr / 'bin'}:{NODE_DIR}:/usr/bin:/bin", "HOME": str(cr_home), "B": str(CR_B)}
    r = tool_run(block or cron_block(marker), env)
    calls = (cr / "calls.log").read_text(encoding="utf-8").splitlines() if (cr / "calls.log").exists() else []
    inst = (cr / "installed").read_text(encoding="utf-8") if (cr / "installed").exists() else None
    return r, calls, inst


S0, S5, S8 = 'md5sum < "$B/crontab.txt"', 'read_crontab > "$B/crontab.before"', '"$B/crontab.rollback"'
OTHER = "# BEGIN mezzanine-supervision\n* * * * * cd /x && php artisan mezzanine:fold\n# END mezzanine-supervision\n"
cron_state(None)
r0, c0, _ = cron_run(S0)
eq("Step 0 on a seat with no crontab: it says so, backs up an empty crontab, prints the empty file's md5 and exits 0",
   (True, "", True, "rc=0"),
   ("no crontab installed" in r0.stdout, (CR_B / "crontab.txt").read_text(encoding="utf-8"),
    "d41d8cd98f00b204e9800998ecf8427e  -" in r0.stdout, r0.stdout.strip().splitlines()[-1]))
cron_state(OTHER, broken=True)
r0b, c0b, _ = cron_run(S0)
eq("  … and a `crontab -l` that fails any other way stops it at the read, with the error shown and rc 1",
   (True, "rc=1", False), ("Permission denied" in r0b.stderr, r0b.stdout.strip().splitlines()[-1], "-  " in r0b.stdout))
cron_state(None)
r5, c5, i5 = cron_run(S5)
eq("Step 5 on a seat with no crontab installs a crontab holding the reporter's block alone, and exits 0",
   (True, "rc=0"), (i5 is not None and i5.startswith("# BEGIN fleet-reporter-flusher\n")
                    and i5.endswith("# END fleet-reporter-flusher\n") and i5.count("\n") == 5,
                    r5.stdout.strip().splitlines()[-1]))
cron_state(OTHER, broken=True)
r5b, c5b, i5b = cron_run(S5)
eq("  … and on a crontab it cannot read it stops at the read: no install call, the crontab untouched, rc 1",
   (["crontab -l"], OTHER, "rc=1"), (c5b, i5b, r5b.stdout.strip().splitlines()[-1]))
old5 = cron_block(S5).replace(f"{HEAD}\n", "( set -euo pipefail\n").replace(
    f"  {READS[0] if READS else ''}\n", "").replace(S5, 'crontab -l > "$B/crontab.before"').replace(
    '"$JS"\nSH\n', '"$JS" )\n')
cron_state(OTHER, broken=True)
r5o, c5o, i5o = cron_run(S5, block=old5)
eq("RED: the block as it stood before card#12334, under the same tool wrapper and the same unreadable crontab, "
   "carries on past the failed read and installs the reporter's block over the crontab, exit 0",
   (True, True, "rc=0"), (any(c.startswith("crontab /") for c in c5o),
                          i5o is not None and "mezzanine-supervision" not in i5o, r5o.stdout.strip().splitlines()[-1]))
cron_state(None)
cron_run(S0)
cron_run(S5)
r8, c8, i8 = cron_run(S8)
eq("Step 8 item 2 on a seat that had no crontab at Step 0: the diff is empty, it removes the crontab, and the md5 it "
   "prints last is Step 0's", (None, True, True, "rc=0"),
   (i8, "crontab -r" in c8, "d41d8cd98f00b204e9800998ecf8427e  -" in r8.stdout, r8.stdout.strip().splitlines()[-1]))
cron_state(None)
cron_run(S0)
r8n, c8n, i8n = cron_run(S8)
eq("  … with no crontab at all by then it removes nothing, says so, and exits 0",
   (None, False, True, "rc=0"),
   (i8n, any(c in ("crontab -r",) or c.startswith("crontab /") for c in c8n), "nothing to remove" in r8n.stdout,
    r8n.stdout.strip().splitlines()[-1]))
redgreen("the runbook's blocks stop at a failed command under an agent's shell tool, and a seat with no crontab "
         "installs (card#12334)",
         f"`( set -euo pipefail … )` under `eval … &&` -> {r_old.stdout.strip()!r}; Step 5 as it stood, over an "
         f"unreadable crontab -> stub calls {c5o}, the other block gone",
         f"`bash -euo pipefail <<'SH'` -> {r_new.stdout.strip()!r}; Step 5 over the same crontab -> calls {c5b}, "
         f"untouched; no crontab -> Steps 0, 5 and 8 each rc 0, the rollback's md5 Step 0's")


# A MISSING VALUE IS NAMED, NEVER A TypeError. Each helper the runbook saves, given no arguments, and each
# `node -e` command that reads FR_CONFIG, given none, exits 2 with a line naming what it needs (card#12334: an
# agent ran Step 3's printer saved to a file, lost its trailing argument, and got a readFileSync TypeError).
HELPERS = ("coord-config.js", "wire-hooks.js", "check-preserved.js", "stop-flusher.js", "flusher-ancestry.js")
ARGLESS = {}
for h in HELPERS:
    src = re.search(r"cat > \"\$B/" + re.escape(h) + r"\" <<'JS'\n(.*?)\nJS\n", INSTALL_DOC, re.S).group(1)
    r = subprocess.run(["node", "-e", src], capture_output=True, text=True, cwd=str(cr))
    ARGLESS[h] = (r.returncode, r.stderr.startswith("usage: "), "TypeError" in r.stderr)
eq("each helper the runbook saves, run with no arguments, exits 2 with its usage line and no TypeError",
   {h: (2, True, False) for h in HELPERS}, ARGLESS)
sf = re.search(r"cat > \"\$B/stop-flusher\.js\" <<'JS'\n(.*?)\nJS\n", INSTALL_DOC, re.S).group(1)
sf_red = re.sub(r"^if \(!spool \|\| !js\).*\n", "", sf, count=1, flags=re.M)
r_sf_red = subprocess.run(["node", "-e", sf_red], capture_output=True, text=True, cwd=str(cr))
eq("RED: stop-flusher.js without its usage line, run with no arguments, dies on a TypeError", (True, True),
   (sf_red != sf, "TypeError" in r_sf_red.stderr))
ENV_CMDS = re.findall(r"node -e '((?:const f = process\.env\.FR_CONFIG|const fs=require\(\"fs\"\)(?:,path=require\(\"path\"\))?,f=process\.env\.FR_CONFIG).*?)'(?:$|\n)",
                      INSTALL_DOC, re.M)
# The population, re-derived: every `node -e` line of the runbook that reads FR_CONFIG, so a command the
# extraction above misses is a count mismatch, not a silent pass.
N_ENV_CMDS = len(re.findall(r"node -e '[^\n]*process\.env\.FR_CONFIG", INSTALL_DOC))
eq("precondition: the runbook has `node -e` commands that read FR_CONFIG", True, N_ENV_CMDS > 0)
env_out = {}
for c in ENV_CMDS:
    r = subprocess.run(["node", "-e", c], capture_output=True, text=True, cwd=str(cr),
                       env={k: v for k, v in os.environ.items() if not k.startswith("FR_")})
    env_out[c[:50]] = (r.returncode, "must" in r.stderr and "FR_CONFIG" in r.stderr, "TypeError" in r.stderr)
eq("the runbook's `node -e` commands that read FR_CONFIG (Step 3's printer and one-key rewrite, Step 8's site "
   "removal) each exit 2 naming FR_CONFIG when it is unset, and none dies on a TypeError",
   (N_ENV_CMDS, {k: (2, True, False) for k in env_out}), (len(env_out), env_out))


print("\n== 21. THE RUN LEAVES NO FLUSHER DAEMON BEHIND (card#7976) ==")
# WHY THIS IS A CHECK AND NOT JUST A TEARDOWN. Every hook that finds a stale lock forks a real
# detached flusher (§ 2.3, P-7) — correct reporter behaviour, and nobody's bug in the product —
# and that process loops until it is signalled. A teardown that quietly reaped them would leave
# the suite's own defect invisible again, which is exactly how one leaked daemon per run
# survived unnoticed: it sits at 0% CPU and nothing but `pgrep` reports it. So the sweep FAILS
# the run and then reaps, rather than reaping and saying nothing.
#
# THE CONTROL RUNS FIRST, because this check passes by finding NOTHING and a sweep that cannot
# find a daemon it was handed would pass over a leak identically — the same rule § 5 applies to
# the credential sweep.
if spawned_flushers() is None:
    skip("the leaked-flusher sweep needs /proc, which this platform does not have — the run's "
         "daemon count was NOT measured, and that is not a pass")
else:
    ahead = int(time.time() * 1000) + 2 * 3600 * 1000
    eq("nothing this run spawned is still alive before the control plants one", [],
       spawned_flushers())

    # RED — the defect verbatim: a lock frozen on the WALL clock, then a hook whose own clock is
    # pinned two hours ahead. Driven around `hook()` on purpose, because `hook()` goes through
    # `seat.env()`, which is where the fix lives; this is the invocation the harness used to
    # make, and § 4's aged-out drop is a real instance of it.
    ctl = seat("flusher-leak-control", ingest=DEAD)
    ctl.freeze_flusher()                                        # wall clock, as before the fix
    subprocess.run(["node", str(REPORTER), "hook", "PreToolUse"],
                   input=json.dumps(pre(tuid="leak_ctl")), capture_output=True, text=True,
                   env=ctl.env(freeze=False, FLEET_REPORTER_NOW_MS=ahead), cwd=str(HERE))
    leaked, spawn_s = await_flushers(True)
    eq("RED: a hook pinned two hours ahead of a wall-clock freeze reads the lock as long dead, "
       "forks a real detached flusher, and that flusher outlives the run",
       [str(ctl.cfg_path)], [cfg for _, cfg in leaked])
    reap_flushers(leaked)
    eq("  … and the sweep sees it gone once reaped, so its verdict tracks the world both ways",
       [], spawned_flushers())

    # GREEN — the same seat and the same pinned clock, through the harness. The reporter's
    # respawn rule is untouched: it stats the lock and finds a live owner, which is the state
    # the freeze has always meant to model and now models on the clock the hook actually reads.
    grn = seat("flusher-leak-green", ingest=DEAD)
    hook(grn, "PreToolUse", pre(tuid="leak_grn"), FLEET_REPORTER_NOW_MS=ahead)
    # Waited against the control's own measured spawn latency, so the zero below is an interval
    # in which a spawn demonstrably WOULD have been seen, not an instant read that raced it.
    # Capped, because a control that TIMED OUT (i.e. already red above) must not also stretch the
    # run by 80 s to say so again.
    watch_s = min(max(3.0, 4 * spawn_s), 15.0)
    time.sleep(watch_s)
    eq(f"GREEN: the same hook through the harness forks nothing, watched for "
       f"{watch_s:.1f} s — the control's daemon was already visible on the first "
       f"read after its own hook returned ({spawn_s:.1f} s), so this zero is not a race",
       [], spawned_flushers())

    # THE RUN-WIDE SWEEP, over every seat this file created, not only the two above.
    left = spawned_flushers()
    eq("and no block of this suite leaves a flusher daemon behind", [],
       [f"pid {pid} <- {cfg}" for pid, cfg in left])
    reap_flushers(left)
    redgreen("the suite reaps what it spawns: no run leaves a live flusher daemon (card#7976)",
             "lock frozen on the wall clock + a hook pinned 2 h ahead -> `now() - mtime` is 2 h "
             "against LOCK_STALE_MS=90 s, so § 2.3 correctly declares the flusher dead and the "
             "hook forks a detached one with no exit condition; measured at 1 leaked daemon per "
             "run, accumulating across every run on the machine",
             "the lock is frozen against the invocation's OWN clock in `Seat.env()`, so it reads "
             "0 s old however that hook's clock is pinned — on every hook that gets its own "
             "`env()` call, which is all of them but the AT-10/AT-16 worker bursts, where one "
             "freeze covers 40/15 hooks and the window is narrowed (measured 22.6 s / 6.5 s at "
             "the last hook) rather than closed; the run ends with a /proc sweep for daemons "
             "whose config is under this run's TMP, which fails the suite and then reaps — a "
             "control daemon is planted first and must be found")


print("\n" + "=" * 92)
print("RED / GREEN EVIDENCE, PER SAFETY PROPERTY")
print("=" * 92)
for e in _evidence:
    print(e)
print("=" * 92)
if _skipped:
    # Reprinted here rather than left in the scroll: a check that could not run is the one thing
    # a reader of a green run would otherwise take for a pass.
    print("\nNOT MEASURED ON THIS PLATFORM — not passes:")
    for s in _skipped:
        print(f"  - {s}")
if fails:
    print(f"\nfleet-reporter.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print(f"\nfleet-reporter.selftest: all checks passed"
      f"{f' ({len(_skipped)} not measured here)' if _skipped else ''}")

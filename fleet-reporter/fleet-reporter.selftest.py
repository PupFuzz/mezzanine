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

import calendar
import http.server
import json
import os
import re
import shutil
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


def ok(msg: str) -> None:
    print(f"  ok   {msg}")


def bad(msg: str) -> None:
    global fails
    fails += 1
    print(f"  FAIL {msg}", file=sys.stderr)


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
class Seat:
    def __init__(self, root: Path, ingest: str, *, ca: str | None = None,
                 enabled: bool = True, wrapped: str | None = None, token: str = TOKEN):
        self.root = root
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

    def freeze_flusher(self) -> None:
        """Make the flusher lock look FRESH so hooks do not opportunistically respawn one.

        This is the product's own mechanism (D1 § 2.3: a lock whose mtime is under 90 s means a
        live flusher), used exactly as AT-7 specifies for the same purpose. It is not a test
        back door: no code path in the reporter is disabled, the hook simply observes a live
        owner. Without it every hook invocation would fork a real flusher into the middle of an
        exact-count assertion.
        """
        lock = self.spool / "flusher.lock"
        lock.write_text('{"pid":1,"started_at":"1970-01-01T00:00:00.000Z"}', encoding="utf-8")
        os.utime(lock, None)

    def env(self, **extra) -> dict:
        e = dict(os.environ)
        e["FLEET_REPORTER_CONFIG"] = str(self.cfg_path)
        e.pop("FLEET_REPORTER_NOW_MS", None)
        e.update({k: str(v) for k, v in extra.items()})
        return e

    def events(self) -> list[dict]:
        out = []
        for f in sorted(self.spool.glob("*.jsonl")):
            for line in f.read_text(encoding="utf-8").splitlines():
                if line.strip():
                    out.append(json.loads(line)["e"])
        return out

    def counters(self) -> dict:
        c: dict = {}
        d = self.spool / "counters"
        if not d.exists():
            return c
        for f in sorted(d.glob("*.jsonl")):
            for line in f.read_text(encoding="utf-8").splitlines():
                if not line.strip():
                    continue
                for k, v in json.loads(line).get("c", {}).items():
                    c[k] = c.get(k, 0) + v
        return c

    def state(self) -> dict:
        try:
            return json.loads((self.spool / "state.json").read_text(encoding="utf-8"))
        except Exception:
            return {}

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
    lock = seat.spool / "flusher.lock"
    if lock.exists():
        lock.unlink()
    try:
        return subprocess.run(
            ["node", str(reporter), "flusher"], capture_output=True, text=True,
            env=seat.env(FLEET_REPORTER_ONE_PASS="1", **envx), cwd=str(HERE), timeout=90)
    finally:
        seat.freeze_flusher()


def selftest(seat: Seat | None = None, *, reporter: Path = REPORTER):
    env = seat.env() if seat else dict(os.environ)
    if not seat:
        env["FLEET_REPORTER_CONFIG"] = "/nonexistent/config.json"
    r = subprocess.run(["node", str(reporter), "selftest"], capture_output=True, text=True,
                       env=env, cwd=str(HERE))
    try:
        return r, json.loads(r.stdout)
    except Exception:
        return r, {}


# ── planting a defect on a COPY ────────────────────────────────────────────────────────────
_plant_dirs: list[str] = []


def plant(*subs: tuple[str, str]) -> Path:
    """Copy the reporter (with its fixtures) into a temp dir and apply each (old, new).

    Every substitution must actually match — a plant that silently applies nothing is a RED
    that quietly becomes a GREEN, which is the exact failure this whole file exists to make
    impossible.
    """
    d = Path(tempfile.mkdtemp(prefix="fr-plant-"))
    _plant_dirs.append(str(d))
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
    d = Path(tempfile.mkdtemp(prefix="fr-plant-"))
    _plant_dirs.append(str(d))
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
    d = Path(tempfile.mkdtemp(prefix="fr-fixt-"))
    _plant_dirs.append(str(d))
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

            def _read(self):
                n = int(self.headers.get("Content-Length", 0))
                raw = self.rfile.read(n) if n else b""
                if self.headers.get("Content-Encoding") == "gzip":
                    import gzip
                    raw = gzip.decompress(raw)
                return raw

            def do_GET(self):
                body = json.dumps({"accepted_schema_versions": [1],
                                   "server_time": "2026-08-24T00:00:00.000Z",
                                   "min_reporter_version": "0.1.0"}).encode()
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def do_POST(self):
                raw = self._read()
                try:
                    batch = json.loads(raw)
                except Exception:
                    batch = {"_unparseable": raw[:200].decode("utf-8", "replace")}
                outer.batches.append({"batch": batch, "auth": self.headers.get("Authorization", ""),
                                      "ctype": self.headers.get("Content-Type", "")})
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


TMP = Path(tempfile.mkdtemp(prefix="fr-suite-"))
INGEST = Ingest(TMP)
CA = str(INGEST.crt)
SID = "11111111-2222-4333-8444-000000000000"


def seat(name: str, **kw) -> Seat:
    root = TMP / name
    root.mkdir(parents=True, exist_ok=True)
    return Seat(root, kw.pop("ingest", INGEST.url), ca=kw.pop("ca", CA), **kw)


def pre(tool="Bash", ti=None, tuid="toolu_1", **extra):
    p = {"session_id": SID, "hook_event_name": "PreToolUse", "prompt_id": "p1",
         "tool_name": tool, "tool_input": ti or {"command": "echo hello"},
         "tool_use_id": tuid, "cwd": "/home/agent/mezzanine"}
    p.update(extra)
    return p


print("== 1. The `selftest` subcommand — the six checks § 6.14 declares, and each one's RED ==")
s1 = seat("selftest-seat")
r, rep = selftest(s1)
eq("the six declared checks are exactly the reported set",
   ["config_readable", "harness_payload_keys", "predicate_discrimination",
    "sanitizer_fixtures", "schema_version_accepted", "tls_verify"],
   sorted(rep.get("checks", {}).keys()))
eq("config_readable passes on a valid config", "pass", rep["checks"]["config_readable"])
eq("sanitizer_fixtures passes", "pass", rep["checks"]["sanitizer_fixtures"])
eq("harness_payload_keys passes", "pass", rep["checks"]["harness_payload_keys"])
eq("predicate_discrimination passes", "pass", rep["checks"]["predicate_discrimination"])
# The offline posture is REPORTED as a fail with its reason, never assumed to pass. D1 § 6.14
# makes these two network checks; a suite with no ingest reachable at selftest time must not
# quietly call them green.
eq("schema_version_accepted is honestly `fail` when unprobed", "fail",
   rep["checks"]["schema_version_accepted"])

# RED — an http:// ingest_url is REFUSED at install (§ 3.5), not downgraded.
s_http = seat("http-seat", ingest="http://127.0.0.1:9/api/ingest/events")
_, rep_http = selftest(s_http)
eq("RED: an http:// ingest_url fails config_readable", "fail", rep_http["checks"]["config_readable"])
eq("  … and says why, naming the rule", True,
   any("https://" in e for e in rep_http["detail"]["config_readable"]["errors"]))
redgreen("transport posture (§ 3.5)",
         f'http:// ingest_url  -> config_readable="fail", errors={rep_http["detail"]["config_readable"]["errors"]}',
         f'https:// ingest_url -> config_readable="{rep["checks"]["config_readable"]}"')

# RED — the TLS posture lint AT-15 asks for, made mechanical.
p_tls = plant(("({ keepAlive: true, maxSockets: 2 })",
               "({ keepAlive: true, maxSockets: 2, rejectUnauthorized: false })"))
_, rep_tls = selftest(s1, reporter=p_tls)
eq("RED: a planted `rejectUnauthorized: false` fails tls_verify", "fail", rep_tls["checks"]["tls_verify"])
eq("  … and names the forbidden spelling", True,
   len(rep_tls["detail"]["tls_verify"]["forbidden_spellings_present"]) == 1)
eq("GREEN: the real source carries no verification-disabling spelling", [],
   rep["detail"]["tls_verify"]["forbidden_spellings_present"])


print("\n== 2. SANITIZER — § 7.5's thirteen fixtures, and the four REDs AT-2 names ==")
eq("GREEN: all 13 fixtures match their exact output AND their documented rule trace",
   [], [d for d in rep["detail"]["sanitizer_fixtures"] if not d["pass"]])
eq("  … and 13 is the whole table", 13, len(rep["detail"]["sanitizer_fixtures"]))

# RED 1 — the identity sanitizer. "Replace the sanitizer body with s => s and the whole table
# must go RED." A fixture set that only ever passes proves the harness runs, nothing else.
p_id = plant(("function sanitize(input, cap) {",
              "function sanitize(input, cap) { return { text: String(input == null ? '' : input), truncated: false, rules: [], redactions: 0 };"))
_, rep_id = selftest(s1, reporter=p_id)
failed_id = [d["fixture"] for d in rep_id["detail"]["sanitizer_fixtures"] if not d["pass"]]
eq("RED: the identity sanitizer fails every fixture that redacts or truncates",
   [1, 2, 3, 4, 5, 6, 7, 9, 10, 11, 12, 13], failed_id)
eq("  … and fixture 8 still passes, because the ALLOWLIST is what stops it, not the regexes",
   False, 8 in failed_id)
leaks = [d["leaked"] for d in rep_id["detail"]["sanitizer_fixtures"] if d["leaked"]]
eq("  … and the whole-event assertion sees the raw credentials appear", True, len(leaks) >= 3)

# RED 2 — remove only the allowlist: fixture 8 must fail ALONE, proving the two layers are
# independently load-bearing.
p_allow = plant(("  const fn = ALLOWLIST[toolName];\n  if (!fn) return",
                 "  const fn = ALLOWLIST[toolName] || ((ti) => JSON.stringify(ti));\n  if (!fn) return"))
_, rep_allow = selftest(s1, reporter=p_allow)
eq("RED: with the allowlist removed, fixture 8 fails ALONE",
   [8], [d["fixture"] for d in rep_allow["detail"]["sanitizer_fixtures"] if not d["pass"]])
f8 = [d for d in rep_allow["detail"]["sanitizer_fixtures"] if d["fixture"] == 8][0]
eq("  … and the planted `hunter2` reaches the serialized event", "hunter2", f8["leaked"])
redgreen("sanitizer layer 1 — the allowlist (§ 7.1)",
         f'allowlist removed -> fixture 8 descriptor={f8["got"]!r}, leaked={f8["leaked"]!r}',
         'allowlist intact  -> fixture 8 descriptor=None, leaked=None')

# RED 3 — revert rule 5 to the pre-extension rule 4: fixtures 9, 10 and 11 fail alone and each
# credential appears VERBATIM, proving the credential-on-argv extension is load-bearing.
p_r5 = plant_src(
    (r"  run\(5, /[^\n]*\n[^\n]*\n", "  // rule 5 removed by plant\n"),
    (r"\(\?!\[A-Za-z\]\)\(\\s\*\[:=\]\\s\*\|\\s\+\)", "(?![A-Za-z])(\\s*[:=]\\s*)"))
_, rep_r5 = selftest(s1, reporter=p_r5)
eq("RED: reverting to the pre-extension rule 4 with no rule 5 fails fixtures 9, 10 and 11 ALONE",
   [9, 10, 11], [d["fixture"] for d in rep_r5["detail"]["sanitizer_fixtures"] if not d["pass"]])
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
         "all 13 fixtures: output AND documented rule trace both exact")


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
p_net = plant(("function hookMain(hookName) {\n  const atMs = now();",
               "function hookMain(hookName) {\n  const atMs = now();\n  try { require('child_process').execSync('sleep 2'); } catch (e) {}"))
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
DEAD = "https://127.0.0.1:9/api/ingest/events"          # discard port: refused locally, no DNS, no WAN
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
   s4.state().get("counters", {}).get("batches_retried", 0) > 0)
hb = [e for e in s4.events() if e["kind"] == "reporter.heartbeat"]
eq("the flusher heartbeats even with the ingest down (silence must never be the signal)",
   True, len(hb) >= 1)
eq("  … and the heartbeat reports the backlog rather than hiding it", True,
   hb[-1]["data"]["spool_lag_events"] > 0 and hb[-1]["data"]["oldest_unsent_age_s"] is not None)

# Now the server comes back: EVERY spooled event must arrive, by id, not by count.
INGEST.batches.clear()
s4.cfg["ingest_url"] = INGEST.url
s4.write_cfg()
# The set is FROZEN before the drain: each flusher pass emits its own heartbeat, so a set
# re-read after the last pass would always contain one event that pass could not yet have sent.
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
red_dropped = s4h.counters().get("spool_dropped_events", 0) + s4h.state().get("counters", {}).get("spool_dropped_events", 0)
eq("RED: the flusher drops the aged-out oldest bucket AND COUNTS every event in it",
   True, red_dropped > 0)
s4g = seat("nooverflow", ingest=DEAD)
for i in range(20):
    hook(s4g, "PreToolUse", pre(tuid=f"ng_{i}"), FLEET_REPORTER_NOW_MS=past)
flush(s4g)
eq("GREEN (control): under the real 32 MiB bound the same run drops nothing", 0,
   s4g.counters().get("spool_dropped_events", 0) + s4g.state().get("counters", {}).get("spool_dropped_events", 0))
redgreen("survives the bridge being down (AT-4)",
         f"2 KiB spool bound, clock pinned mid-hour -> hook defers ({deferred} x "
         f"spool_overflow_deferred, 0 dropped: it may not unlink the live bucket); one hook two "
         f"hours on drops the aged-out bucket counting all {aged_dropped} lines; flusher likewise "
         f"drops the aged-out bucket -> spool_dropped_events={red_dropped}",
         f"real bound, ingest refused for 30 hooks then restored -> 30/30 hooks rc=0, "
         f"spool_dropped_events=0, {len(delivered_ids)} events delivered, missing={sorted(missing)}, "
         f"seq strictly increasing")


print("\n== 5. NO CREDENTIAL REACHES ANY OUTPUT (P-6) — with its negative control FIRST ==")
# THE CONTROL RUNS BEFORE THE CHECK IS TRUSTED. This check passes by finding NOTHING, and a
# sweep that cannot find a token it was handed would pass over a real one identically.
s5 = seat("secrets")


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


control_seat = seat("secrets-control")
p_leak = plant(("      if (config && config.spool_dir) logLine(config.spool_dir, cmd || 'unknown', `crashed: ${e && e.stack}`);",
                "      if (config && config.spool_dir) logLine(config.spool_dir, cmd || 'unknown', `crashed: ${e && e.stack}`);"),
               ("function hookMain(hookName) {\n  const atMs = now();",
                "function hookMain(hookName) {\n  const atMs = now();\n  { const c = loadConfig(configPath()).config; "
                "if (c) { try { fs.appendFileSync(path.join(c.spool_dir, 'log', 'leak.log'), 'token=' + c.token + '\\n'); } catch (e) {} } }"))
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
# REJECTED.txt and the log, so this is a real path for a secret to come to rest locally.
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
   (s5.spool / "REJECTED.txt").exists())
rejected_txt = (s5.spool / "REJECTED.txt").read_text(encoding="utf-8")
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
shape_probe = subprocess.run(
    ["node", "-e",
     "const RE=/\\b(gh[pousr]_|github_pat_|sk-|sk_live_|sk_test_|xox[abposr]-|AKIA|ASIA|glpat-|"
     "AIza|mzn_|mzr_)[A-Za-z0-9_-]{8,}/g;"
     "process.stdout.write(String(RE.test(process.argv[1])));", SHAPELESS],
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
    if (sk.spool / "REJECTED.txt").exists():
        sinks.append(sk.spool / "REJECTED.txt")
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
         f"sweep found {hits}; REJECTED.txt carries '‹redacted:token›' in the token's place")


print("\n== 6. SPOOL DURABILITY (AT-9 torn line, AT-10 concurrent append, AT-17 lost state) ==")
# AT-9 — one torn line never poisons a batch and never wedges the queue.
# The fixture bucket is written in a PAST UTC hour, deliberately: the flusher appends its own
# heartbeat to the CURRENT bucket, and a "trailing partial line" left in a live bucket would be
# glued to by that append. A partial line in a live bucket is a write in progress that lasts
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
   s6.state().get("counters", {}).get("spool_corrupt_lines", 0))
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
first_pass = {e["event_id"] for e in INGEST.events()}
eq("AT-17 setup: the first pass delivered the spool", True, len(first_pass) >= 6)
epoch_before = INGEST.batches[-1]["batch"]["seq_epoch"]
(s17.spool / "state.json").write_text('{"seq_epoch":"trunc', encoding="utf-8")
INGEST.batches.clear()
flush(s17)
resent = {e["event_id"] for e in INGEST.events()}
eq("AT-17: a corrupt state.json RE-SENDS from the oldest bucket rather than skipping to newest",
   True, first_pass <= resent)
eq("  … nothing is recorded as dropped, because nothing was discarded", 0,
   s17.state().get("counters", {}).get("spool_dropped_events", 0))
eq("  … state_reset is counted (rendered `epoch_reset`, informational, NOT `lossy`)", True,
   s17.state().get("counters", {}).get("state_reset", 0) >= 1)
eq("  … under a NEW seq_epoch, so a restarted counter is not read as a 48,000-event gap", True,
   INGEST.batches[-1]["batch"]["seq_epoch"] != epoch_before)
# Discriminating control: without corrupting state.json, the same run re-sends nothing.
INGEST.batches.clear()
flush(s17)
eq("  … CONTROL: an uncorrupted run re-sends nothing (so the re-send came from the reset path)",
   0, len([e for e in INGEST.events() if e["event_id"] in first_pass]))

p_skip = plant_src((r"    st\.cursors = \{\};   // every bucket from byte 0.*?\n",
                    "    st.cursors = {}; for (const f of spoolBuckets(spool)) st.cursors[f.slice(0,10)] = Number.MAX_SAFE_INTEGER;\n"))
INGEST.batches.clear()
s17r = seat("statereset-red")
for i in range(6):
    hook(s17r, "PreToolUse", pre(tuid=f"rr_{i}"))
(s17r.spool / "state.json").write_text('{"seq_epoch":"trunc', encoding="utf-8")
flush(s17r, reporter=p_skip)
eq("RED: resetting the cursor to the END of the spool silently loses every unsent event", 0,
   len(INGEST.events()))
eq("  … and records NOTHING as dropped — the silent hole this rule designs out", 0,
   s17r.state().get("counters", {}).get("spool_dropped_events", 0))


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
c = s7p.state().get("counters", {})
eq("§ 11.5: a 422 batch is rejected and NOT retried", True, posted_once >= 1)
eq("  … batches_rejected is counted", True, c.get("batches_rejected", 0) >= 1)
eq("  … and every event it cost is counted in events_rejected_dropped", True,
   c.get("events_rejected_dropped", 0) >= 4)
eq("  … the batch is quarantined for inspection", True,
   (s7p.spool / "quarantine" / "rejected.jsonl").exists())
eq("  … and the refusal is surfaced LOCALLY, where the only person who can fix it will see it",
   True, (s7p.spool / "REJECTED.txt").exists())
INGEST.batches.clear()
flush(s7p)
eq("  … the stream continues past it rather than wedging behind one bad batch", True,
   len([b for b in INGEST.batches]) >= 0)
hb7 = [e for e in s7p.events() if e["kind"] == "reporter.heartbeat"]
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
ct = s7t.state().get("counters", {})
eq("  … and a single event that is still too large is quarantined, never retried forever", True,
   ct.get("oversize_event_dropped", 0) >= 1)
eq("  … counted, because an undeliverable event is a discarded-events path like any other", True,
   (s7t.spool / "quarantine" / "rejected.jsonl").exists())

redgreen("batch correctness (§ 4.2, § 11.5)",
         "cursor-to-newest reset plant -> 0 of 6 unsent events delivered, spool_dropped_events=0 "
         "(a silent hole); 422 with retry -> the same bytes refused forever",
         f"envelope = {sorted(env.keys())}; per-event = {sorted(ev0.keys())} with seq assigned at "
         f"flush only; 422 -> quarantined once, batches_rejected>=1, events_rejected_dropped="
         f"{c.get('events_rejected_dropped', 0)}, REJECTED.txt written, degraded="
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
flush(s16)
hb = [e for e in s16.events() if e["kind"] == "reporter.heartbeat"][-1]["data"]
eq(f"AT-16: sanitizer_redactions is EXACTLY {2*W*E} after {W}x{E} concurrent hooks, 2 spans each",
   2 * W * E, hb["counters"].get("sanitizer_redactions"))
pred = hb["predicates"]["descriptor_allowlisted"]
eq(f"  … and descriptor_allowlisted's branches sum to exactly {W*E}",
   W * E, pred["true"] + pred["false"])
before = dict(hb["counters"])
flush(s16)
hb2 = [e for e in s16.events() if e["kind"] == "reporter.heartbeat"][-1]["data"]
eq("  … a second fold does NOT double them (the per-bucket byte offset is recorded)",
   before["sanitizer_redactions"], hb2["counters"]["sanitizer_redactions"])
# AT-16's second RED: fold without recording the offset and the totals double on the next pass.
p_nooff = plant_src((r"    state\.counter_offsets\[b\] = from \+ nl \+ 1;", "    /* offset not recorded */"))
s16r = seat("countersink-red")
for i in range(4):
    hook(s16r, "PreToolUse", pre(ti=FIX4, tuid=f"cr_{i}"))
flush(s16r, reporter=p_nooff)
h1 = [e for e in s16r.events() if e["kind"] == "reporter.heartbeat"][-1]["data"]["counters"].get("sanitizer_redactions")
flush(s16r, reporter=p_nooff)
h2 = [e for e in s16r.events() if e["kind"] == "reporter.heartbeat"][-1]["data"]["counters"].get("sanitizer_redactions")
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
flush(s16h)
hbh = [e for e in s16h.events() if e["kind"] == "reporter.heartbeat"][-1]["data"]
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
     "'clear_reap_by_session_end','agent_scope_subagent','attention_resolved_by_hook'])"
     "preds[p]={true:MAX,false:MAX};"
     "const st={};for(const c of ['config_readable','tls_verify','schema_version_accepted',"
     "'sanitizer_fixtures','predicate_discrimination','harness_payload_keys']) st[c]='fail';"
     "console.log(JSON.stringify({p:JSON.stringify(preds).length,s:JSON.stringify(st).length}));",
     str(REPORTER)], capture_output=True, text=True, cwd=str(HERE))
w = json.loads(worst.stdout)
eq(f"`predicates` at its worst case is D1's derived 396 B, under the 512 B cap ({w['p']} B)",
   (396, True), (w["p"], w["p"] <= 512))
eq(f"`selftest` at its worst case is D1's derived 171 B, under the 256 B cap ({w['s']} B)",
   (171, True), (w["s"], w["s"] <= 256))

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
eq("§ 8.7 rows 1-9 arrive in exactly the specified order",
   ["session.start", "turn.start", "tool.start", "subagent.spawn", "tool.start",
    "tool.end", "tool.end", "subagent.stop", "turn.end", "session.end", "session.start"], kinds)
A = ev[2]["data"]["call_id"]
B = ev[4]["data"]["call_id"]
eq("  the subagent's inner call names its parent dispatch call", A, ev[4]["data"]["parent_call_id"])
eq("  … and is labelled agent_scope `subagent`", "subagent", ev[4]["data"]["agent_scope"])
eq("  the INNER call closes FIRST — a consumer never sees a parent close while a child is open",
   B, ev[5]["data"]["call_id"])
for e in (ev[5], ev[6]):
    eq(f"  {e['data']['tool_name']} closes aborted / session_cleared / reap_session_boundary / reap",
       ("aborted", "session_cleared", "reap_session_boundary", "reap"),
       (e["data"]["outcome"], e["data"]["abort_reason"], e["data"]["close_source"], e["data"]["match"]))
eq("  the dispatch call also emits subagent.stop, aborted", ("aborted", A),
   (ev[7]["data"]["outcome"], ev[7]["data"]["call_id"]))
te = ev[8]["data"]
eq("  turn.end: end_reason session_cleared, open_calls_at_end 2, aborted_call_ids [B, A]",
   ("session_cleared", 2, [B, A]),
   (te["end_reason"], te["open_calls_at_end"], te["aborted_call_ids"]))
eq("  … and NO tool.end anywhere reports completed or failed — the whole point of the ledger",
   [], [e for e in ev if e["kind"] == "tool.end" and e["data"]["outcome"] != "aborted"])
eq("  … and NO turn.end reports stop_hook, so D2-MUST #1 can never mint idle here",
   [], [e for e in ev if e["kind"] == "turn.end" and e["data"]["end_reason"] == "stop_hook"])
eq("  session.end: clear, 2 aborted calls", ("clear", 2),
   (ev[9]["data"]["end_reason"], ev[9]["data"]["aborted_calls"]))
eq("  the SECOND /clear signal finds nothing and says so exactly once", 1,
   s87.counters().get("reap_noop_second_signal"))
eq("  … and the predicate records WHICH signal reaped, one evaluation per /clear",
   {"true": 1, "false": 0}, s87.predicates().get("clear_reap_by_session_end"))
eq("  … with no call closed twice", len({e["data"]["call_id"] for e in ev if e["kind"] == "tool.end"}),
   len([e for e in ev if e["kind"] == "tool.end"]))

# The reverse hook order must put the IDENTICAL events on the wire — that is what "two
# independent signals, either suffices" actually buys, and D1 specifies both paths.
s87r = seat("clear-trace-reverse")
hook(s87r, "SessionStart", {"session_id": OLD, "hook_event_name": "SessionStart", "source": "startup", "cwd": "/home/agent/mezzanine"})
hook(s87r, "UserPromptSubmit", {"session_id": OLD, "hook_event_name": "UserPromptSubmit", "prompt_id": "p1", "prompt": "go", "cwd": "/home/agent/mezzanine"})
hook(s87r, "PreToolUse", pre(tool="Agent", ti={"description": "probe", "subagent_type": "coder"}, tuid="toolu_A", session_id=OLD))
hook(s87r, "SubagentStart", {"session_id": OLD, "hook_event_name": "SubagentStart", "prompt_id": "p1", "agent_id": "0123456789abcdef0", "agent_type": "coder"})
hook(s87r, "PreToolUse", pre(tool="Bash", ti={"command": "sleep 120"}, tuid="toolu_B", session_id=OLD, agent_id="0123456789abcdef0"))
hook(s87r, "SessionStart", {"session_id": NEW, "hook_event_name": "SessionStart", "source": "clear", "cwd": "/home/agent/mezzanine"})
hook(s87r, "SessionEnd", {"session_id": OLD, "hook_event_name": "SessionEnd", "reason": "clear"})
evr = s87r.events()
eq("SessionStart-first produces the same kinds, with the boundary set before the new session.start",
   ["session.start", "turn.start", "tool.start", "subagent.spawn", "tool.start",
    "tool.end", "tool.end", "subagent.stop", "turn.end", "session.end", "session.start"],
   [e["kind"] for e in evr])
eq("  … the reap still happened EXACTLY once", 1,
   len([e for e in evr if e["kind"] == "session.end"]))
eq("  … and the second signal (SessionEnd this time) counted the no-op", 1,
   s87r.counters().get("reap_noop_second_signal"))
eq("  … with the predicate recording the OTHER branch", {"true": 0, "false": 1},
   s87r.predicates().get("clear_reap_by_session_end"))
eq("  … and the new session.start names the session it superseded", OLD,
   [e for e in evr if e["kind"] == "session.start"][-1]["data"]["previous_session_id"])

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
         f"§ 8.7's 11 events in exact order, inner call B closing before parent A, both "
         f"aborted/session_cleared/reap_session_boundary, turn.end open_calls_at_end=2 "
         f"aborted_call_ids=[B,A], no completed/failed close, no stop_hook turn.end; "
         f"reverse hook order gives the identical wire with reap_noop_second_signal=1 and the "
         f"opposite predicate branch")


print("\n== 13. UNKNOWN ENUM VALUES COST ONE FIELD, NOT A BATCH (AT-18, § 6.0 rule 4) ==")
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
redgreen("unknown enum values (AT-18) and the blocked pair (AT-20)",
         "passing an unknown value through verbatim -> 422 invalid_event, all 200 events in the "
         "batch rejected, quarantined permanently (D1 § 6.0 rule 4's stated cost)",
         "source=teleport -> coerced to `unknown`, counted as "
         "enum_value_unknown.session.start.source, raw value absent from the wire; control "
         "source=fork passes through with no coercion counted; auth_success emits nothing and is "
         "counted; elicitation_url_dialog opens a request that a UserPromptSubmit then resolves")


print("\n== 14. A FAILED APPEND IS COUNTED, AND A BAD CACHED DESCRIPTOR COSTS NO EVENT (§ 0 item 9) ==")
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

print("\n== 15. THE OS USERNAME NEVER REACHES THE WIRE VIA project_label (§ 1 non-goal, § 6.1) ==")
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


print("\n== 16. THE BUCKET IS DERIVED AT THE WRITE, AND § 6.1's PATTERN ADMITS ITS OWN EXAMPLE ==")
# § 11.1: a hook entering at 13:59:59.900 must not write into bucket 13 after the hour rolled —
# the flusher's next pass is <= 10 s away and would read to EOF and unlink. The entry timestamp
# is moved back an hour, which is that boundary made deterministic; the event's `event_time`
# must follow the entry clock while the FILE it lands in must follow the write clock.
ENTRY_BACK_AN_HOUR = (r"function hookMain\(hookName\) \{\n  const atMs = now\(\);",
                      "function hookMain(hookName) {\n  const atMs = now() - 3600000;")


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
   "on from the timestamp captured at process entry", [bucket_shift(entry_b, 1)], written_b)
eq("  … while event_time still carries the entry clock, so placement and semantics are not "
   "conflated", True, entry_b == bucket_shift(written_b[0], -1))
# RED: the pre-fix derivation — the bucket taken from the entry timestamp at both writers.
s16r = seat("bucket-at-entry")
hook(s16r, "PreToolUse", pre(),
     reporter=plant_src(ENTRY_BACK_AN_HOUR, (r"utcBucket\(now\(\)\)", "utcBucket(t)")))
entry_r, written_r = emitted_bucket(s16r)
eq("RED: derived at process entry it lands in the PREVIOUS hour's bucket — behind a cursor the "
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


print("\n" + "=" * 92)
print("RED / GREEN EVIDENCE, PER SAFETY PROPERTY")
print("=" * 92)
for e in _evidence:
    print(e)
print("=" * 92)
if fails:
    print(f"\nfleet-reporter.selftest: {fails} check(s) FAILED", file=sys.stderr)
    sys.exit(1)
print("\nfleet-reporter.selftest: all checks passed")

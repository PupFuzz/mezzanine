#!/usr/bin/env python3
"""The round trip: the REAL fleet-reporter posting REAL batches to the REAL ingest, over TLS.

WHY THIS EXISTS AND THE PHPUnit SUITE DOES NOT REPLACE IT
════════════════════════════════════════════════════════
The PHPUnit suite drives fixtures this repository wrote, from the same document the endpoint was
written from — so it proves the document was read twice. It cannot prove the endpoint accepts what
`fleet-reporter.js` actually emits, because a shared misreading produces a matching fixture and a
matching validator and a green suite.

`fleet-reporter/` is merged on `dev` (card #7335, PR #12). So this harness runs it: real hook
invocations write a real spool, the real flusher builds real batches, and they cross a real TLS
connection with certificate verification ON to a real `php artisan serve`. Nothing about the batch
is authored here.

WHAT IT COVERS THAT NOTHING ELSE DOES
─────────────────────────────────────
  1. the round trip itself — every event the reporter emitted is in `events`, byte-for-byte
  2. AT-9  — a torn spool line does not poison the batch, end to end, with its RED
  3. AT-13 — the reporter half: a permanently-refused batch quarantines rather than retrying,
             and `next_seq` does not advance past events that were never accepted
  4. the health surface answering the reporter's own `schema_version_accepted` selftest

RUNNING IT
──────────
    python3 server/tests/roundtrip/ingest-roundtrip.py [--keep]

Needs `php`, `node` and `openssl` on PATH, and three free localhost ports. It is deliberately NOT
part of `composer test`: it binds sockets, spawns processes and mints a throwaway CA, none of which
belongs in a unit-test lane. It is a gate to run before trusting the endpoint against a real seat.

THE DATABASE IS SQLITE, AND THAT IS STATED RATHER THAN HIDDEN. `docs/design/FLEET-STATE.md § 6.1`
pins production to MySQL ≥ 8.0.12 on a dedicated host; that host does not exist yet (card #7523)
and this seat holds no MySQL credential. So this harness runs on a temporary SQLite file, exactly
as `phpunit.xml` does, and the two things that buys are stated plainly at the end of the run: the
wire contract and the ingest's logic are exercised for real, and the MySQL-specific parts of the
DDL — `ascii_bin` collation behaviour, `ON DUPLICATE KEY`, the `ENUM` columns' storage-layer
refusal — are NOT.
"""

import argparse
import hashlib
import json
import os
import re
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
SERVER = HERE.parent.parent
ROOT = SERVER.parent
REPORTER = ROOT / "fleet-reporter" / "fleet-reporter.js"
FIXTURES = ROOT / "fleet-reporter" / "fixtures" / "hooks"

INSTALL_ID = "aimla"
SEAT_ID = "aimla-pm"

GREEN, RED, DIM, BOLD, OFF = "\033[32m", "\033[31m", "\033[2m", "\033[1m", "\033[0m"

results = []


def record(name, ok, detail=""):
    results.append((name, ok, detail))
    mark = f"{GREEN}GREEN{OFF}" if ok else f"{RED}RED  {OFF}"
    print(f"  {mark}  {name}" + (f"  {DIM}{detail}{OFF}" if detail else ""))
    return ok


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def wait_for_port(port, timeout=25.0, what="port"):
    deadline = time.time() + timeout
    while time.time() < deadline:
        with socket.socket() as s:
            s.settimeout(0.4)
            if s.connect_ex(("127.0.0.1", port)) == 0:
                return True
        time.sleep(0.15)
    raise RuntimeError(f"{what} {port} never came up")


class Harness:
    """Everything the round trip needs, torn down in reverse."""

    def __init__(self, workdir: Path):
        self.work = workdir
        self.spool = workdir / "spool"
        self.config_path = workdir / "config.json"
        self.db = workdir / "mezzanine.sqlite"
        self.procs = []
        self.token = None
        self.tls_port = None

    # ── infrastructure ───────────────────────────────────────────────────────────────────────

    def mint_ca(self):
        """A throwaway CA and a localhost leaf, trusted through the reporter's own `ca_file`."""
        ca_key, ca_crt = self.work / "ca.key", self.work / "ca.crt"
        srv_key, srv_crt = self.work / "srv.key", self.work / "srv.crt"
        csr = self.work / "srv.csr"
        ext = self.work / "srv.ext"
        ext.write_text(
            "subjectAltName=DNS:localhost,IP:127.0.0.1\n"
            "basicConstraints=CA:FALSE\n"
            "keyUsage=critical,digitalSignature,keyEncipherment\n"
            "extendedKeyUsage=serverAuth\n"
        )

        # `keyUsage=keyCertSign` is not decoration: OpenSSL 3.5 (Python 3.14's) refuses a CA
        # certificate that omits it outright — "CA cert does not include key usage extension" —
        # so a CA minted without it would fail verification and the honest reading of that
        # failure is a broken harness, not a broken transport. Getting it right here keeps
        # certificate verification ON, which is the whole point of the terminator.
        ca_ext = self.work / "ca.ext"
        ca_ext.write_text(
            "basicConstraints=critical,CA:TRUE\n"
            "keyUsage=critical,keyCertSign,cRLSign,digitalSignature\n"
        )

        run = lambda *a: subprocess.run(a, check=True, capture_output=True)
        run("openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2",
            "-keyout", str(ca_key), "-out", str(ca_crt), "-subj", "/CN=mezzanine-roundtrip-ca",
            "-extensions", "v3_ca", "-addext", "basicConstraints=critical,CA:TRUE",
            "-addext", "keyUsage=critical,keyCertSign,cRLSign,digitalSignature")
        run("openssl", "req", "-newkey", "rsa:2048", "-nodes",
            "-keyout", str(srv_key), "-out", str(csr), "-subj", "/CN=localhost")
        run("openssl", "x509", "-req", "-in", str(csr), "-CA", str(ca_crt), "-CAkey", str(ca_key),
            "-CAcreateserial", "-out", str(srv_crt), "-days", "2", "-extfile", str(ext))

        self.ca_file, self.srv_key, self.srv_crt = ca_crt, srv_key, srv_crt

    def env(self):
        e = dict(os.environ)
        e.update({
            "APP_ENV": "local",
            "DB_CONNECTION": "sqlite",
            "DB_SQLITE_DATABASE": str(self.db),
            "DB_DATABASE": str(self.db),
            "CACHE_STORE": "file",
            "SESSION_DRIVER": "file",
            "QUEUE_CONNECTION": "sync",
            "APP_DEBUG": "false",
        })
        return e

    def artisan(self, *args, check=True):
        return subprocess.run(
            ["php", "artisan", *args],
            cwd=SERVER, env=self.env(), check=check, capture_output=True, text=True,
        )

    def start(self):
        self.db.touch()
        self.artisan("config:clear")
        self.artisan("migrate", "--force")

        # The token, from the real command. Its plaintext is printed once and stored nowhere, so
        # this is the only place it can be read — which is the property D1 § 3.3 asks for.
        out = self.artisan("mezzanine:ingest-token:issue", INSTALL_ID, SEAT_ID, "--by=roundtrip").stdout
        m = re.search(r"(mzn_[A-Za-z0-9_-]{43})", out)
        if not m:
            raise RuntimeError(f"could not read the issued token from:\n{out}")
        self.token = m.group(1)

        http_port = free_port()
        self.tls_port = free_port()

        self.procs.append(subprocess.Popen(
            ["php", "artisan", "serve", "--host=127.0.0.1", f"--port={http_port}"],
            cwd=SERVER, env=self.env(), stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        ))
        wait_for_port(http_port, what="php artisan serve")

        self.procs.append(subprocess.Popen(
            ["node", str(HERE / "tls-terminator.cjs"), str(self.tls_port), str(http_port),
             str(self.srv_crt), str(self.srv_key)],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        ))
        wait_for_port(self.tls_port, what="tls terminator")

        self.write_config()

    def write_config(self, **overrides):
        """D1 § 3.1's seat config — the reporter's ONLY source of identity."""
        cfg = {
            "install_id": INSTALL_ID,
            "seat_id": SEAT_ID,
            "ingest_url": f"https://localhost:{self.tls_port}/api/ingest/events",
            "token": self.token,
            "spool_dir": str(self.spool),
            "ca_file": str(self.ca_file),
            "proxy_url": None,
            "wrapped_statusline": None,
            "harness_label": "claude-code/2.1.240",
            "enabled": True,
        }
        cfg.update(overrides)
        self.config_path.write_text(json.dumps(cfg, indent=2))
        os.chmod(self.config_path, 0o600)

    def stop(self):
        for p in reversed(self.procs):
            p.send_signal(signal.SIGTERM)
            try:
                p.wait(timeout=5)
            except subprocess.TimeoutExpired:
                p.kill()

    # ── driving the real reporter ────────────────────────────────────────────────────────────

    def reporter_env(self, script=None):
        e = dict(os.environ)
        e["FLEET_REPORTER_CONFIG"] = str(self.config_path)
        return e

    def hook(self, name, payload, script=None):
        """One real hook invocation: the hook name as argv[2], the payload on stdin."""
        return subprocess.run(
            ["node", str(script or REPORTER), "hook", name],
            input=json.dumps(payload), text=True, capture_output=True, env=self.reporter_env(),
        )

    def stop_background_flusher(self):
        """Stop the flusher the REPORTER started, before driving a deterministic scenario.

        D1 P-7 (§ 2.3, `maybeRespawnFlusher`): every hook invocation opportunistically respawns a
        dead flusher, detached and unref'd, so a real seat always has one running. That is correct
        producer behaviour and the round-trip check below relies on it — but a scenario that
        rebuilds the spool by hand needs to be the only writer, or its own `FLEET_REPORTER_ONE_PASS`
        pass exits with "another flusher owns the lock" and the assertions measure a spool
        somebody else was mutating.
        """
        lock = self.spool / "flusher.lock"

        if not lock.exists():
            return

        try:
            pid = json.loads(lock.read_text())["pid"]
            os.kill(pid, signal.SIGTERM)
            for _ in range(40):
                time.sleep(0.1)
                try:
                    os.kill(pid, 0)
                except ProcessLookupError:
                    break
        except (ValueError, KeyError, ProcessLookupError, PermissionError):
            pass

        lock.unlink(missing_ok=True)

    def wait_for_delivery(self, expected, timeout=60.0):
        """Wait for the reporter's OWN flusher to drain, which is what a real seat does.

        § 11.5's flush trigger is 10 s elapsed, so this polls rather than assuming. Returning on
        a timeout rather than raising is deliberate: the caller asserts on the count, so a partial
        drain reports as the number it reached instead of as a harness crash.
        """
        deadline = time.time() + timeout
        while time.time() < deadline:
            if len(self.stored_events()) >= expected:
                return True
            time.sleep(1.0)
        return False

    def flush(self, script=None):
        """One flusher pass, through the script's own `FLEET_REPORTER_ONE_PASS` test seam."""
        e = self.reporter_env()
        e["FLEET_REPORTER_ONE_PASS"] = "1"
        return subprocess.run(
            ["node", str(script or REPORTER), "flusher"],
            capture_output=True, text=True, env=e, timeout=90,
        )

    # ── reading the store ────────────────────────────────────────────────────────────────────

    def query(self, sql):
        out = self.artisan("tinker", "--execute",
                           f'echo json_encode(\\DB::select({json.dumps(sql)}));').stdout
        m = re.search(r"(\[.*\])\s*$", out, re.S)
        return json.loads(m.group(1)) if m else []

    def reset_store(self):
        """Between scenarios. `stored_events()` is a TOTAL, and a scenario asserting `0 stored`
        that reads a previous scenario's rows is measuring the wrong population — which is how
        AT-13's headline assertion first reported RED against a working endpoint."""
        self.artisan("tinker", "--execute",
                     'echo \\DB::table("events")->delete(), \\DB::table("batches")->delete();')

    def stored_events(self):
        return self.query(
            "select event_id, kind, event_time, seq, seq_epoch, session_id, data "
            "from events order by seq"
        )

    def counter(self, name):
        rows = self.query(f"select value from seat_counters where name = '{name}'")
        return int(rows[0]["value"]) if rows else 0


def fixture_payload(hook_name):
    """A real captured harness payload — D1 § 17's, vendored beside the reporter."""
    raw = json.loads((FIXTURES / f"{hook_name}.json").read_text())
    shapes = raw.get("shapes", [raw])
    payload = dict(shapes[0])
    payload.setdefault("hook_event_name", hook_name)
    return payload


# ═════════════════════════════════════════════════════════════════════════════════════════════
# The checks
# ═════════════════════════════════════════════════════════════════════════════════════════════

def check_health(h):
    """The reporter's `schema_version_accepted` selftest reads this surface (D1 § 6.14)."""
    req = urllib.request.Request(
        f"https://localhost:{h.tls_port}/api/ingest/health",
        headers={"Authorization": f"Bearer {h.token}"},
    )
    import ssl
    ctx = ssl.create_default_context(cafile=str(h.ca_file))
    with urllib.request.urlopen(req, context=ctx, timeout=15) as r:
        body = json.loads(r.read())

    reporter_version = re.search(r"const SCHEMA_VERSION = (\d+)", REPORTER.read_text()).group(1)

    record(
        "health reports an accepted set containing the reporter's own SCHEMA_VERSION",
        int(reporter_version) in body["accepted_schema_versions"],
        f"reporter={reporter_version} accepted={body['accepted_schema_versions']}",
    )
    return body


def check_round_trip(h):
    """Drive a real session's worth of hooks, flush, and compare the spool against the store."""
    for hook in ["SessionStart", "UserPromptSubmit", "PreToolUse", "PostToolUse", "Stop"]:
        r = h.hook(hook, fixture_payload(hook))
        if r.returncode != 0 or r.stdout.strip():
            # D1 P-1: every hook exits 0 and prints nothing on stdout, on every path.
            record(f"hook {hook} exits 0 and prints nothing", False,
                   f"rc={r.returncode} stdout={r.stdout!r}")
            return

    spooled = []
    for bucket in sorted(h.spool.glob("*.jsonl")):
        for line in bucket.read_text().splitlines():
            if line.strip():
                spooled.append(json.loads(line)["e"])

    if not record("the real reporter spooled events", len(spooled) > 0, f"{len(spooled)} lines"):
        return

    # NOT `h.flush()`. The reporter has already started its own detached flusher (D1 P-7), and
    # letting THAT one deliver is the point: this is the path a real seat runs, end to end, with
    # nothing in it authored by this harness.
    h.wait_for_delivery(len(spooled))
    stored = h.stored_events()

    record(
        "every event the reporter emitted was accepted and stored",
        len(stored) == len(spooled),
        f"spooled={len(spooled)} stored={len(stored)}",
    )

    by_id = {e["event_id"]: e for e in stored}
    mismatched = [
        s["event_id"] for s in spooled
        if s["event_id"] not in by_id or by_id[s["event_id"]]["kind"] != s["kind"]
    ]
    record("every spooled event_id is present under its own kind", not mismatched, str(mismatched[:3]))

    # `data` is stored opaque (D2 § 6.3). Compare it field for field against what the reporter
    # actually wrote — this is the assertion a hand-written fixture cannot make.
    drifted = []
    for s in spooled:
        stored_data = json.loads(by_id[s["event_id"]]["data"]) if s["event_id"] in by_id else None
        if stored_data != s["data"]:
            drifted.append((s["kind"], s["data"], stored_data))
    record("stored `data` is byte-equal to what the reporter emitted", not drifted,
           f"{len(drifted)} differ" if drifted else "")

    # And the ordering key survived (D2-MUST #4).
    record(
        "event_time and seq are stored as the seat wrote them",
        all(by_id[s["event_id"]]["event_time"].replace(" ", "T").startswith(s["event_time"][:19])
            for s in spooled if s["event_id"] in by_id),
    )


def check_at9(h):
    """AT-9 — a torn spool line does not poison the batch. GREEN and RED, end to end."""
    print(f"\n{BOLD}AT-9  a torn spool line does not poison the batch{OFF}")

    def build_torn_spool(spool: Path):
        """AT-9's stated build: valid, truncated-with-newline, valid, trailing partial."""
        shutil.rmtree(spool, ignore_errors=True)
        spool.mkdir(parents=True)

        # A PREVIOUS hour's bucket, deliberately. § 11.1 buckets the spool by UTC hour and the
        # flusher emits its own `reporter.heartbeat` into the CURRENT one, so a torn bucket built
        # for the current hour gets a terminated heartbeat line appended immediately after the
        # unterminated partial — a state a real seat cannot reach, because every append is one
        # atomic `writeSync` of one whole line and a partial is by definition a write in progress
        # (§ 11.2, § 11.4). Building it an hour back keeps AT-9's stated shape — the partial IS
        # the file's last bytes — and is also where a torn line actually comes from: a bucket
        # whose hour ended mid-write.
        bucket = spool / (time.strftime("%Y%m%d%H", time.gmtime(time.time() - 3600)) + ".jsonl")

        def line(seq_hint, kind):
            eid = "01K3TA" + "".join("0123456789ABCDEFGHJKMNPQRSTVWXYZ"[(seq_hint * 7 + i) % 32]
                                     for i in range(20))
            return json.dumps({
                "v": 1, "t": "2026-08-23T14:23:09.882Z",
                "e": {
                    "event_id": eid, "kind": kind,
                    "event_time": "2026-08-23T14:23:09.882Z",
                    "install_id": INSTALL_ID, "seat_id": SEAT_ID,
                    "session_id": "e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913",
                    "data": {"prompt_chars": 100 + seq_hint, "project_label": "mezzanine"},
                },
            })

        good_a, good_b = line(1, "turn.start"), line(2, "turn.start")
        truncated = line(3, "turn.start")[: len(line(3, "turn.start")) // 2]
        trailing = line(4, "turn.start")

        # valid \n  truncated \n  valid \n  partial-with-NO-newline
        bucket.write_text(f"{good_a}\n{truncated}\n{good_b}\n{trailing[:40]}")
        return bucket, trailing, [json.loads(good_a)["e"]["event_id"], json.loads(good_b)["e"]["event_id"]]

    h.stop_background_flusher()
    h.reset_store()

    # ── RED first, per the brief: a parser that throws on the batch delivers nothing ──────────
    defective = h.work / "fleet-reporter-defective.js"
    src = REPORTER.read_text()
    # AT-9's own stated RED: "make the parser throw on the batch". The reporter's line reader
    # quarantines an unparseable line and continues; removing that recovery is the defect.
    patched, n = re.subn(
        r"try \{ rec = JSON\.parse\(line\); \} catch \(e\) \{[^}]*\}",
        "rec = JSON.parse(line);",
        src,
    )
    if n == 0:
        # A plant that matches nothing must RAISE, never silently pass — a RED that has quietly
        # become a GREEN is worse than no test (`fleet-reporter/README.md`).
        patched, n = re.subn(r"function parseSpoolLine\(", "function parseSpoolLine_unused(", src)
        record("AT-9 RED plant found its target in fleet-reporter.js", n > 0,
               "the parser-recovery pattern moved; the RED below is not evidence")
        if n == 0:
            return
    defective.write_text(patched)

    bucket, trailing, good_ids = build_torn_spool(h.spool)
    h.flush(script=defective)
    delivered_red = len(h.stored_events())
    record("AT-9 RED — with the parser's recovery removed, the torn bucket delivers nothing",
           delivered_red == 0, f"{delivered_red} events delivered")

    # ── GREEN: the real reporter ─────────────────────────────────────────────────────────────
    h.stop_background_flusher()
    h.reset_store()
    bucket, trailing, good_ids = build_torn_spool(h.spool)
    h.flush()
    stored = h.stored_events()
    stored_ids = {e["event_id"] for e in stored}

    record("AT-9 GREEN — both valid lines are delivered",
           all(i in stored_ids for i in good_ids), f"{len(stored)} stored")

    corrupt = h.spool / "quarantine" / "corrupt.jsonl"
    corrupt_lines = len(corrupt.read_text().splitlines()) if corrupt.exists() else 0
    record("AT-9 GREEN — the truncated line is quarantined, spool_corrupt_lines == 1",
           corrupt_lines == 1 and h.counter("spool_corrupt_lines") in (0, 1),
           f"quarantine lines={corrupt_lines}")

    body = bucket.read_text()
    record("AT-9 GREEN — the trailing partial line is untouched",
           body.endswith(trailing[:40]) and not body.endswith("\n"),
           f"bucket ends unterminated with {len(body) - body.rfind(chr(10)) - 1} bytes")

    # ...and is delivered intact once it is completed on the next pass.
    with bucket.open("a") as f:
        f.write(trailing[40:] + "\n")
    h.flush()
    completed_id = json.loads(trailing)["e"]["event_id"]
    record("AT-9 GREEN — the completed line is delivered intact on the next pass",
           completed_id in {e["event_id"] for e in h.stored_events()})


def check_at13_reporter_half(h):
    """AT-13's reporter-side GREEN, driven against the real endpoint."""
    print(f"\n{BOLD}AT-13  atomic batch rejection — the reporter half, over the wire{OFF}")

    h.stop_background_flusher()
    h.reset_store()
    shutil.rmtree(h.spool, ignore_errors=True)
    h.spool.mkdir(parents=True)
    bucket = h.spool / (time.strftime("%Y%m%d%H", time.gmtime()) + ".jsonl")

    lines = []
    for i in range(200):
        eid = "01K3TB" + "".join("0123456789ABCDEFGHJKMNPQRSTVWXYZ"[(i * 11 + j) % 32] for j in range(20))
        # Event 137 carries `data` over the 3 KiB cap — AT-13's build exactly. A real reporter
        # cannot produce this (§ 6.0 rule 5 clamps first), which is why the spool is written
        # directly here while the FLUSHER, the transport and the ingest are all real.
        data = ({"prompt_chars": 1, "project_label": "x" * 3200} if i == 137
                else {"prompt_chars": 412, "project_label": "mezzanine"})
        lines.append(json.dumps({
            "v": 1, "t": "2026-08-23T14:23:09.882Z",
            "e": {"event_id": eid, "kind": "turn.start", "event_time": "2026-08-23T14:23:09.882Z",
                  "install_id": INSTALL_ID, "seat_id": SEAT_ID,
                  "session_id": "e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913", "data": data},
        }))
    bucket.write_text("\n".join(lines) + "\n")

    h.flush()

    record("AT-13 — 0 of 200 stored", len(h.stored_events()) == 0,
           f"{len(h.stored_events())} stored")

    rejected_txt = h.spool / "REJECTED.txt"
    record("AT-13 — the reporter wrote REJECTED.txt", rejected_txt.exists(),
           rejected_txt.read_text().splitlines()[0][:100] if rejected_txt.exists() else "")

    if rejected_txt.exists():
        text = rejected_txt.read_text()
        record("AT-13 — REJECTED.txt names the machine-readable error code",
               "invalid_event" in text)
        record("AT-13 — REJECTED.txt contains no Authorization header value",
               h.token not in text and "Bearer" not in text,
               "§ 11.5: the reporter logs the request's status, never its headers")

    quarantined = h.spool / "quarantine" / "rejected.jsonl"
    record("AT-13 — the batch is quarantined rather than retried", quarantined.exists())

    # The stream continues: a fresh good batch after the poison pill.
    h.hook("UserPromptSubmit", fixture_payload("UserPromptSubmit"))
    h.flush()
    record("AT-13 — the stream continues with the next batch", len(h.stored_events()) > 0,
           f"{len(h.stored_events())} stored after the poison pill")


def check_auth_over_the_wire(h):
    """The seat token is the ONLY credential this surface accepts, proven on a real connection."""
    print(f"\n{BOLD}Auth, over the real transport{OFF}")

    import ssl
    ctx = ssl.create_default_context(cafile=str(h.ca_file))

    def post(token):
        req = urllib.request.Request(
            f"https://localhost:{h.tls_port}/api/ingest/events",
            data=b'{"schema_version":1}',
            headers={"Content-Type": "application/json; charset=utf-8",
                     **({"Authorization": f"Bearer {token}"} if token else {})},
        )
        try:
            with urllib.request.urlopen(req, context=ctx, timeout=15) as r:
                return r.status, json.loads(r.read())
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read())

    status, body = post(None)
    record("no credential is 401 unauthenticated", status == 401 and body["error"] == "unauthenticated")

    status, body = post("mzn_" + "A" * 43)
    record("a wrong seat token is 401 unauthenticated", status == 401)

    status, body = post("mzr_" + "A" * 43)
    record("a read-plane token is refused on the ingest surface", status == 401)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--keep", action="store_true", help="keep the working directory")
    args = ap.parse_args()

    for tool in ("php", "node", "openssl"):
        if shutil.which(tool) is None:
            print(f"{RED}missing required tool: {tool}{OFF}")
            return 2

    work = Path(tempfile.mkdtemp(prefix="mezzanine-roundtrip-"))
    h = Harness(work)

    print(f"{BOLD}Mezzanine ingest round trip{OFF}  {DIM}{work}{OFF}\n")

    try:
        h.mint_ca()
        h.start()

        print(f"{BOLD}The real producer, over TLS with verification on{OFF}")
        check_health(h)
        check_round_trip(h)
        check_auth_over_the_wire(h)
        check_at9(h)
        check_at13_reporter_half(h)
    finally:
        h.stop()
        if not args.keep:
            shutil.rmtree(work, ignore_errors=True)
        else:
            print(f"\n{DIM}kept: {work}{OFF}")

    failed = [n for n, ok, _ in results if not ok]

    print(f"\n{BOLD}{len(results) - len(failed)}/{len(results)} checks passed{OFF}")
    print(f"{DIM}Store: SQLite. NOT exercised: MySQL's ascii_bin collation, ON DUPLICATE KEY, and{OFF}")
    print(f"{DIM}the ENUM columns' storage-layer refusal — the MySQL host does not exist yet (#7523).{OFF}")

    if failed:
        print(f"\n{RED}failed:{OFF}")
        for n in failed:
            print(f"  - {n}")
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())

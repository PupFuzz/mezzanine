#!/usr/bin/env python3
"""AT-1's drive half — start a REAL throwaway Claude Code session, get a tool call genuinely
in flight, and `/clear` it so the harness kills the call.

`docs/design/EVENT-SCHEMA.md` AT-1 is the authority for what this proves; this file restates
none of it. What lives here is the *mechanics* AT-1 cannot state in prose: how a `/clear` is
delivered to a real TUI, and how the rig knows the call was open when it landed.

    drive.py --scratch DIR --tag NAME --reporter PATH [--mode subagent|main]

**The `/clear` is timed off the reporter's own index journal, never off a sleep.** The driver
polls `<spool>/index/*.jsonl` until the target call has an `open` record and no `close`, and
only then types `/clear`. A wall-clock delay would make a passing run unfalsifiable: a clear
that landed after the call ended would produce the same clean stream as a design that works.

⛔ ISOLATION — every path this writes is under `--scratch`, and the harness runs under a
`CLAUDE_CONFIG_DIR` inside it. It never reads or writes a live seat's session, spool, counters
or logs. See README.md § Isolation; the credential prerequisite there is OPERATOR-RUN and this
script REFUSES to substitute for it (see `MissingCredentialError`).
"""
import argparse
import fcntl
import json
import os
import pty
import select
import signal
import struct
import sys
import termios
import time

HOOKS = ["SessionStart", "SessionEnd", "UserPromptSubmit", "Stop", "StopFailure", "PreToolUse",
         "PostToolUse", "PostToolUseFailure", "SubagentStart", "SubagentStop", "PreCompact",
         "PostCompact", "PermissionRequest", "PermissionDenied", "Notification"]

# The command the in-flight call runs. NOT `sleep 120`: the harness refuses a standalone sleep
# outright (MEASURED 2.1.245 — "Blocked: standalone sleep 120 … use Monitor with an
# until-loop"), so AT-1's literal fixture cannot be driven and this is the sanctioned shape.
# The sentinel is never created by the rig; the call ends when the harness kills it.
LOOP = "until [ -f {sentinel} ]; do sleep 2; done"


class MissingCredentialError(RuntimeError):
    """The scratch config dir holds no credential.

    Raised INSTEAD of falling back to the operator's live credential file. A rig that reaches
    for `~/.claude/.credentials.json` on its own would (a) put a live token into a second
    place on disk without anyone deciding to, and (b) make a public-repo script the thing that
    decided it. README.md § The credential prerequisite states the operator-run steps,
    including the shred-after.
    """


def parse_args(argv=None):
    p = argparse.ArgumentParser(description="AT-1 kill-vs-complete: drive a real /clear")
    p.add_argument("--scratch", required=True, help="scratch root; EVERYTHING is written under it")
    p.add_argument("--tag", required=True, help="run name, e.g. green1 or red-noreap")
    p.add_argument("--reporter", required=True, help="path to the fleet-reporter.js under test")
    p.add_argument("--mode", choices=("subagent", "main"), default="subagent",
                   help="whose call is in flight when the /clear lands")
    p.add_argument("--claude", default=os.environ.get("CLAUDE_BIN", "claude"),
                   help="the claude binary to drive")
    p.add_argument("--open-timeout", type=float, default=240.0,
                   help="seconds to wait for the target call to open before giving up")
    return p.parse_args(argv)


def require_credential(config_dir):
    """The named refusal. See MissingCredentialError."""
    cred = os.path.join(config_dir, ".credentials.json")
    if not os.path.exists(cred):
        raise MissingCredentialError(
            f"no credential at {cred}.\n"
            "The rig will not read the operator's live credential file. Run the operator-run\n"
            "prerequisite in tools/at1-kill-vs-complete/README.md § The credential prerequisite\n"
            "(copy in, run, shred out) and re-run this driver.")
    return cred


def write_run_config(args, runroot):
    """Per-run reporter config and per-run harness settings. Both scratch, both disposable."""
    spool = os.path.join(runroot, "spool")
    os.makedirs(spool, exist_ok=True)
    src = os.path.join(args.scratch, "reporter-config.json")
    if not os.path.exists(src):
        raise SystemExit(f"no template reporter config at {src} — see README.md § Setup")
    cfg = json.load(open(src))
    cfg["spool_dir"] = spool
    conf_path = os.path.join(runroot, "config.json")
    with open(conf_path, "w") as fh:
        json.dump(cfg, fh, indent=1)
    os.chmod(conf_path, 0o600)

    capture = os.path.join(runroot, "capture.jsonl")
    capture_py = os.path.join(os.path.dirname(os.path.abspath(__file__)), "capture.py")
    # Two commands per hook: the raw capture (evidence INDEPENDENT of the reporter — it is how
    # a claim about what the harness fired survives a reporter that is itself under suspicion)
    # and the reporter under test.
    settings = {
        "hooks": {h: [{"matcher": "*", "hooks": [
            {"type": "command", "command": f"{sys.executable} {capture_py} {h}"},
            {"type": "command", "command": f"node {args.reporter} hook {h}"},
        ]}] for h in HOOKS},
        # The one Bash form the in-flight call needs, plus Task. Narrow on purpose: a broad
        # allow-list would let a wandering model run something the rig never intended.
        "permissions": {"allow": ["Bash(until:*)", "Bash(sleep:*)", "Task"], "deny": []},
        "theme": "dark",
        "includeCoAuthoredBy": False,
    }
    settings_path = os.path.join(runroot, "settings.json")
    with open(settings_path, "w") as fh:
        json.dump(settings, fh, indent=1)
    return conf_path, settings_path, capture, spool


def trust_cwd(config_dir, cwd):
    """Pre-accept the workspace-trust dialog for THIS scratch cwd, in the SCRATCH config dir.

    Without it the TUI opens a modal and the driver's prompt is typed into it. This touches
    only the throwaway config dir.
    """
    p = os.path.join(config_dir, ".claude.json")
    d = json.load(open(p)) if os.path.exists(p) else {}
    d.setdefault("projects", {})[cwd] = {
        "hasTrustDialogAccepted": True, "hasCompletedProjectOnboarding": True,
        "projectOnboardingSeenCount": 1, "allowedTools": [], "history": []}
    with open(p, "w") as fh:
        json.dump(d, fh, indent=1)


def index_records(spool):
    out = []
    d = os.path.join(spool, "index")
    if not os.path.isdir(d):
        return out
    for fn in sorted(os.listdir(d)):
        if not fn.endswith(".jsonl"):
            continue
        for ln in open(os.path.join(d, fn), errors="replace"):
            ln = ln.strip()
            if not ln:
                continue
            try:
                out.append(json.loads(ln))
            except ValueError:
                pass          # a torn last line is expected on a live append-only journal
    return out


def target_call_open(spool, mode):
    """The rig's timing signal: an `open` record with no `close`/`tombstone`, in the right scope."""
    opens, closed = {}, set()
    for r in index_records(spool):
        k = r.get("k")
        if k == "open":
            opens[r.get("call_id")] = r
        elif k in ("close", "tombstone"):
            closed.add(r.get("call_id"))
    for cid, r in opens.items():
        if cid in closed or r.get("tool_name") != "Bash":
            continue
        if (mode == "subagent") == bool(r.get("agent_scope_id")):
            return r
    return None


class Session:
    """A real interactive Claude Code session on a pty, with a transcript."""

    def __init__(self, args, runroot, conf_path, settings_path, capture, config_dir, cwd):
        self.capture = capture
        self.log = open(os.path.join(runroot, "tty.log"), "wb")
        env = dict(os.environ)
        # Scrub the OUTER agent's harness variables: a nested claude that inherits them can
        # bind itself to the parent's session and messaging socket.
        for k in ("CLAUDECODE", "CLAUDE_CODE_ENTRYPOINT", "CLAUDE_CODE_SESSION_ID",
                  "CLAUDE_CODE_CHILD_SESSION", "CLAUDE_CODE_BRIDGE_SESSION_ID",
                  "CLAUDE_CODE_MESSAGING_SOCKET", "CLAUDE_CODE_MESSAGING_TOKEN",
                  "CLAUDE_PID", "CLAUDE_EFFORT", "CLAUDE_CODE_EXECPATH"):
            env.pop(k, None)
        env["CLAUDE_CONFIG_DIR"] = config_dir
        env["FLEET_REPORTER_CONFIG"] = conf_path
        env["CAPTURE_FILE"] = capture
        env["TERM"] = "xterm-256color"
        self.pid, self.fd = pty.fork()
        if self.pid == 0:
            os.chdir(cwd)
            # --strict-mcp-config: without it the TUI can find an MCP config by walking up to
            # the operator's home and opens an approval modal the driver would type into.
            os.execvpe(args.claude, [args.claude, "--settings", settings_path,
                                     "--permission-mode", "acceptEdits", "--strict-mcp-config"],
                       env)
        fcntl.ioctl(self.fd, termios.TIOCSWINSZ, struct.pack("HHHH", 50, 160, 0, 0))
        os.set_blocking(self.fd, False)

    def pump(self, seconds):
        end = time.time() + seconds
        while time.time() < end:
            r, _, _ = select.select([self.fd], [], [], 0.2)
            if not r:
                continue
            try:
                data = os.read(self.fd, 65536)
            except OSError:
                return
            if not data:
                return
            self.log.write(data)
            self.log.flush()

    def send(self, s):
        os.write(self.fd, s.encode())

    def mark(self, msg):
        """A driver marker in the SAME file as the raw hook payloads, so one sorted read shows
        what the rig did interleaved with what the harness fired."""
        now = time.time()
        line = json.dumps({"_hook": "_DRIVER",
                           "_at": time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(now))
                                  + ".%03dZ" % (int(now * 1000) % 1000),
                           "_raw": msg}) + "\n"
        fd = os.open(self.capture, os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
        os.write(fd, line.encode())
        os.close(fd)

    def close(self):
        self.send("\x03")
        self.pump(1)
        self.send("\x03")
        self.pump(1)
        try:
            os.kill(self.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        self.pump(3)
        self.log.close()


def main(argv=None):
    args = parse_args(argv)
    scratch = os.path.abspath(args.scratch)
    config_dir = os.path.join(scratch, "cc")
    runroot = os.path.join(scratch, "runs", args.tag)
    cwd = os.path.join(runroot, "proj")
    os.makedirs(cwd, exist_ok=True)

    require_credential(config_dir)
    conf_path, settings_path, capture, spool = write_run_config(args, runroot)
    trust_cwd(config_dir, cwd)

    sentinel = os.path.join(runroot, "GO")
    loop = LOOP.format(sentinel=sentinel)
    if args.mode == "subagent":
        prompt = ("Use the Task tool exactly once to dispatch a general-purpose subagent. The "
                  "subagent's ONLY job is to run this exact Bash command, verbatim, in the "
                  f"foreground (NOT with run_in_background), and wait for it: {loop} It must run "
                  "nothing else and read nothing. Do not use any other tool yourself.")
    else:
        prompt = ("Run this exact Bash command yourself, verbatim, in the foreground (NOT with "
                  f"run_in_background), and wait for it to return: {loop} Do not dispatch a "
                  "subagent. Do not run anything else.")

    s = Session(args, runroot, conf_path, settings_path, capture, config_dir, cwd)
    print("run root:", runroot)
    s.mark("driver: waiting for the TUI")
    s.pump(20)
    s.send("\x1b")               # dismiss anything modal that appeared anyway
    s.pump(1)
    s.mark("driver: sending the prompt")
    s.send(prompt)
    s.pump(1.5)
    s.send("\r")

    deadline = time.time() + args.open_timeout
    found = None
    while time.time() < deadline and not found:
        s.pump(1.0)
        found = target_call_open(spool, args.mode)
    if not found:
        s.mark("driver: FAILED — no target call was ever open; nothing was killed")
        s.close()
        print("NO TARGET CALL OBSERVED — this run proves nothing; do not read its stream.",
              file=sys.stderr)
        return 2

    s.mark("driver: target call is open: " + json.dumps(found))
    print("target call open:", json.dumps(found))
    s.pump(4)                    # let it be unambiguously mid-flight
    s.mark("driver: sending /clear NOW")
    s.send("/clear")
    s.pump(1.5)
    s.send("\r")
    s.mark("driver: /clear submitted")
    s.pump(20)                   # the late close arrives ~0.2-0.4 s after the reap; 20 s is slack
    s.mark("driver: quitting the session")
    s.close()
    print("done. capture:", capture)
    print("NOTE: a flusher process outlives this run. Kill the one whose "
          "FLEET_REPORTER_CONFIG is under this scratch tree — and ONLY that one.")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except MissingCredentialError as e:
        print(f"AT-1 rig: {e}", file=sys.stderr)
        sys.exit(3)

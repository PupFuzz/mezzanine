#!/usr/bin/env python3
"""The rig's own acceptance suite — hermetic, no credential, no session, no network.

    python3 tools/at1-kill-vs-complete/selftest.py

Driving a real `/clear` needs a credential and a terminal; **reading a stream does not**, and
the reading half is where a silent regression would hide. So the synthetic fixtures below
carry the SHAPES measured on a real seat (card #7337, harness 2.1.245) and this suite asserts
that the rig still tells them apart.

**What it really guards is the rig's power to discriminate.** `derive.py`'s amended reading is
worth nothing if it can only ever answer "not idle" — that is the decoration D1 § 9's own rule
warns about — so every property below is asserted together with a control that must come out
the OTHER way on the same input. If the controls stop disagreeing, this suite fails even
though nothing "broke".

⚠ These fixtures are synthetic by construction and carry no session ids, paths or prompt text
from any real seat. A committed capture from a live seat would leak exactly those (D1 § 6.0's
declined-capture ruling); the shapes are what the tests need.
"""
import json
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import analyze          # noqa: E402
import derive           # noqa: E402
import plant            # noqa: E402
import stream           # noqa: E402

OLD, NEW = "sess-old", "sess-new"
FAILURES = []


def ev(t, kind, session_id, **data):
    return {"v": 1, "t": t, "e": {"event_id": f"e{t}", "kind": kind, "session_id": session_id,
                                  "data": data}}


def write_run(root, events):
    os.makedirs(os.path.join(root, "spool"), exist_ok=True)
    with open(os.path.join(root, "spool", "2026082514.jsonl"), "w") as fh:
        for e in events:
            fh.write(json.dumps(e) + "\n")
    return root


# ── The fixtures ────────────────────────────────────────────────────────────────────────────
# S1 — the measured 2.1.245 subagent kill. The dispatch call closes `completed` 4-45 ms after
# SubagentStart, so the parent turn ends CLEAN while the subagent is alive; the subagent's own
# Bash call opens after that turn.end and is killed by the /clear; the harness then reports the
# kill as `Exit code 137` under the NEW session id.
S1 = [
    ev("2026-08-25T14:21:19.017Z", "session.start", OLD, source="startup"),
    ev("2026-08-25T14:21:21.241Z", "turn.start", OLD),
    ev("2026-08-25T14:21:25.918Z", "tool.start", OLD, call_id="A", tool_name="Agent", agent_scope="main"),
    ev("2026-08-25T14:21:26.269Z", "tool.end", OLD, call_id="A", tool_name="Agent",
       outcome="completed", close_source="post_tool_use", match="harness_ref"),
    ev("2026-08-25T14:21:29.051Z", "turn.end", OLD, end_reason="stop_hook", aborted_call_ids=[],
       open_calls_at_end=0, background_tasks_open=1),
    ev("2026-08-25T14:21:29.290Z", "tool.start", OLD, call_id="B", tool_name="Bash", agent_scope="subagent"),
    ev("2026-08-25T14:21:35.329Z", "tool.end", OLD, call_id="B", tool_name="Bash", outcome="aborted",
       abort_reason="session_cleared", close_source="reap_session_boundary", match="reap"),
    ev("2026-08-25T14:21:35.330Z", "session.end", OLD, end_reason="clear", aborted_calls=1),
    ev("2026-08-25T14:21:35.720Z", "tool.end", NEW, call_id="B", tool_name="Bash", outcome="failed",
       close_source="post_tool_use_failure", match="tombstone_ref"),
]

# S2 — an ordinary finished turn, nothing in the background. The POSITIVE control: idle must be
# reachable, or "no idle" proves nothing.
S2 = [
    ev("2026-08-25T15:00:00.000Z", "session.start", OLD, source="startup"),
    ev("2026-08-25T15:00:01.000Z", "turn.start", OLD),
    ev("2026-08-25T15:00:02.000Z", "tool.start", OLD, call_id="C", tool_name="Bash", agent_scope="main"),
    ev("2026-08-25T15:00:03.000Z", "tool.end", OLD, call_id="C", tool_name="Bash",
       outcome="completed", close_source="post_tool_use", match="harness_ref"),
    ev("2026-08-25T15:00:04.000Z", "turn.end", OLD, end_reason="stop_hook", aborted_call_ids=[],
       open_calls_at_end=0, background_tasks_open=0),
]

# S3 — a main-agent call killed with the turn still open: the reap names it on the turn.end.
S3 = [
    ev("2026-08-25T15:10:00.000Z", "session.start", OLD, source="startup"),
    ev("2026-08-25T15:10:01.000Z", "turn.start", OLD),
    ev("2026-08-25T15:10:02.000Z", "tool.start", OLD, call_id="D", tool_name="Bash", agent_scope="main"),
    ev("2026-08-25T15:10:30.000Z", "tool.end", OLD, call_id="D", tool_name="Bash", outcome="aborted",
       abort_reason="session_cleared", close_source="reap_session_boundary", match="reap"),
    ev("2026-08-25T15:10:30.001Z", "turn.end", OLD, end_reason="session_cleared",
       aborted_call_ids=["D"], open_calls_at_end=1, background_tasks_open=0),
    ev("2026-08-25T15:10:30.002Z", "session.end", OLD, end_reason="clear", aborted_calls=1),
]

# S4 — the same kill with the § 8.3 reap disabled (AT-1's RED): no close of any kind.
S4 = [
    ev("2026-08-25T15:20:00.000Z", "session.start", OLD, source="startup"),
    ev("2026-08-25T15:20:01.000Z", "turn.start", OLD),
    ev("2026-08-25T15:20:02.000Z", "tool.start", OLD, call_id="E", tool_name="Bash", agent_scope="main"),
    ev("2026-08-25T15:20:30.001Z", "turn.end", OLD, end_reason="session_cleared",
       aborted_call_ids=[], open_calls_at_end=1, background_tasks_open=0),
    ev("2026-08-25T15:20:30.002Z", "session.end", OLD, end_reason="clear", aborted_calls=0),
]


def check(name, got, want, note=""):
    ok = got == want
    print(f"  [{'GREEN' if ok else 'RED  '}] {name}: got {got!r}, want {want!r} {note}")
    if not ok:
        FAILURES.append(name)
    return ok


def idle_states(events, reading):
    return [(t, k) for t, s, k in derive.derive(events, reading) if s == "idle"]


def main():
    tmp = tempfile.mkdtemp(prefix="at1-selftest-")
    runs = {name: write_run(os.path.join(tmp, name), fx)
            for name, fx in (("S1", S1), ("S2", S2), ("S3", S3), ("S4", S4))}
    loaded = {k: stream.load(v) for k, v in runs.items()}

    print("§ 1 — the amended reading removes the FALSE idle and keeps the honest one (Q1, Q1b)")
    s1 = loaded["S1"]
    pre = idle_states(s1, derive.PRE_AMENDMENT)
    post = idle_states(s1, derive.AMENDED)
    check("S1 pre-amendment mints an idle on the clean stop_hook turn.end",
          [k for _, k in pre].count("turn.end"), 1, "(the false one — a subagent was alive)")
    check("S1 amended mints NO idle on that turn.end",
          [k for _, k in post].count("turn.end"), 0, "(Q1: background_tasks_open == 1)")
    check("S1 amended still mints the post-clear idle", len(post), 1,
          "(Q1b: after the reap nothing is running, so idle is TRUE)")
    check("S1 pre-amendment mints one MORE idle than amended", len(pre) - len(post), 1)

    print("§ 2 — idle is still reachable, so 'no idle' is a measurement (positive control)")
    for reading in (derive.AMENDED, derive.PRE_AMENDMENT, derive.NAIVE):
        check(f"S2 {reading} mints idle on an ordinary clean turn",
              len(idle_states(loaded["S2"], reading)), 1)

    print("§ 3 — the naive turn-boundary reading DOES mint the false idle (negative control)")
    check("S3 amended mints no idle", len(idle_states(loaded["S3"], derive.AMENDED)), 0)
    check("S3 pre-amendment mints no idle", len(idle_states(loaded["S3"], derive.PRE_AMENDMENT)), 0,
          "(aborted_call_ids is non-empty — D2-MUST #1 holds here without Q1)")
    check("S3 naive mints the false idle", len(idle_states(loaded["S3"], derive.NAIVE)), 1)

    print("§ 4 — the ledger states the kill by its own close reason (Q2)")
    amended, _ = analyze.ledger(loaded["S1"], cross_session_override=False)
    control, late = analyze.ledger(loaded["S1"], cross_session_override=True)
    check("S1 amended: the killed call stays aborted", amended["B"]["outcome"], "aborted")
    check("S1 amended: with the session-cleared reason it was closed for",
          amended["B"]["abort_reason"], "session_cleared")
    check("S1 amended: the cross-session late close was refused",
          amended["B"].get("cross_session_late_close_refused"), True)
    check("S1 pre-amendment CONTROL: the abort is overridden to failed",
          control["B"]["outcome"], "failed", "(the door that reopened the false idle)")
    check("S1 pre-amendment CONTROL: counted as a late completion", late, 1)

    print("§ 5 — with the reap disabled the call is never closed at all (AT-1's RED)")
    red, _ = analyze.ledger(loaded["S4"])
    check("S4 the killed call has no close of any kind", red["E"]["outcome"], None)
    check("S4 its turn.end names nothing",
          (loaded["S4"][-2]["data"]["aborted_call_ids"]), [])

    print("§ 6 — the RED plant applies, and RAISES when it does not")
    reporter = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                            "..", "..", "fleet-reporter", "fleet-reporter.js")
    reporter = os.path.normpath(reporter)
    if os.path.exists(reporter):
        out = os.path.join(tmp, "red", "fleet-reporter.js")
        try:
            plant.plant(reporter, out, "reap-disable")
            applied = open(out).read().count("AT-1 RED PLANT") == 1
        except SystemExit as e:
            applied = f"raised: {e}"
        check("the reap-disable plant applies to the real reporter", applied, True)
    else:
        print(f"  [SKIP ] reporter not found at {reporter}")
    missing = os.path.join(tmp, "no-anchor.js")
    open(missing, "w").write("// a reporter with no reap\n")
    try:
        plant.plant(missing, os.path.join(tmp, "red2", "x.js"), "reap-disable")
        raised = False
    except SystemExit:
        raised = True
    check("a plant that matches nothing RAISES rather than passing", raised, True)

    print()
    if FAILURES:
        print(f"FAILED: {len(FAILURES)} check(s): {', '.join(FAILURES)}")
        return 1
    print("all checks GREEN")
    return 0


if __name__ == "__main__":
    sys.exit(main())

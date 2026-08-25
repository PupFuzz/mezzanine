#!/usr/bin/env python3
"""AT-1's consumer half — what a floor would RENDER for a run, derived from its stream.

`docs/design/FLEET-STATE.md § 4.3` owns `derive_activity`; this runs it. Three readings, and
the differences between them are the whole point:

    amended       — D2 § 4.3 as amended by card #7337 Q1: *idle* additionally requires
                    `background_tasks_open == 0`. A seat with a live subagent IS working.
    pre-amendment — D2 § 4.3 before that ruling. The CONTROL: on a real subagent-kill stream it
                    mints an idle at an instant when a subagent was alive, which is the false
                    idle D1 § 8.1 exists to prevent.
    naive         — the reading D1 § 8.1 names as the defect outright: ANY `turn.end` means the
                    turn finished. The second CONTROL, and the coarsest.

    derive.py RUN_ROOT [RUN_ROOT ...]

**A pass here is evidence only because the two controls can fail on the same input.** If all
three readings ever agree on every fixture, the rig has stopped discriminating and the run
proves nothing — `selftest.py` asserts the disagreement on synthetic streams so that property
is checked rather than assumed.
"""
import sys

import stream

AMENDED, PRE_AMENDMENT, NAIVE = "amended", "pre-amendment", "naive"


def derive(events, reading=AMENDED):
    """D2 § 4.3's five facts, applied event by event. Returns the state-change sequence."""
    attention = stalled = turn_open = False
    open_calls = set()
    last_turn = None                      # (end_reason, aborted_count, background_tasks_open)
    out, prev = [], None
    for e in events:
        kind, d, t = e.get("kind"), e.get("data") or {}, e.get("_t")
        if kind == "tool.start":
            open_calls.add(d.get("call_id"))
        elif kind == "tool.end":
            open_calls.discard(d.get("call_id"))
        elif kind == "turn.start":
            turn_open, stalled = True, False
        elif kind == "turn.end":
            turn_open = False
            if reading == NAIVE:
                last_turn = ("stop_hook", 0, 0)
            else:
                last_turn = (d.get("end_reason"), len(d.get("aborted_call_ids") or []),
                             d.get("background_tasks_open") or 0)
            if d.get("end_reason") == "api_error":
                stalled = True
        elif kind == "attention.request":
            attention = True
        elif kind == "attention.resolved":
            attention = False
        elif kind == "session.end":
            # § 4.3: a session.end clears T, C and S. L survives it, seat-scoped — EXCEPT its
            # background-task count, which the session owned: a background task cannot outlive
            # the session it was spawned in. Without that clearing, Q1's new condition would
            # hold a stale `background_tasks_open: 1` against a seat that is genuinely quiet
            # and render `unknown` where `idle` is true — suppressing the honest state card
            # #7337's Q1b rules must NOT be suppressed.
            turn_open = stalled = False
            open_calls.clear()
            if last_turn is not None:
                last_turn = (last_turn[0], last_turn[1], 0)
        else:
            continue

        state = _state(attention, stalled, open_calls, turn_open, last_turn, reading)
        if state != prev:
            out.append((t, state, kind))
            prev = state
    return out


def _state(attention, stalled, open_calls, turn_open, last_turn, reading):
    if attention:
        return "blocked"
    if stalled:
        return "stalled"
    if open_calls or turn_open:
        return "working"
    if last_turn is None:
        return "unknown(no_data_yet)"
    end_reason, aborted, background = last_turn
    clean = end_reason == "stop_hook" and aborted == 0
    if reading == AMENDED:
        clean = clean and background == 0
    return "idle" if clean else "unknown"


def report(run_root):
    events = stream.load(run_root)
    print("=" * 96)
    print("RUN", run_root)
    for reading in (AMENDED, PRE_AMENDMENT, NAIVE):
        seq = derive(events, reading)
        n = sum(1 for _, s, _ in seq if s == "idle")
        print(f"  {reading:<14} idle transitions = {n}")
        for t, s, k in seq:
            print(f"      {t}  {s:<22} (on {k})")
    print()


if __name__ == "__main__":
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    for r in sys.argv[1:]:
        report(r)

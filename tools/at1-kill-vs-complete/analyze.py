#!/usr/bin/env python3
"""AT-1's ledger half — what the server's call ledger says about each call, and WHY.

Builds D1 § 8.6's ledger from a run's stream and prints every close a call received, so a
killed call's disposition is read off **its own stated close reason** and never off an absence.

Two ledger rules are run side by side, because the difference between them is a ruling this
rig exists to keep checkable:

    amended      — § 8.6 as amended: a late close whose `session_id` differs from the call's
                   own does NOT override the abort (card #7337, Q2). A completion arriving
                   under a different session than the call ran in is the corpse signal of the
                   kill that ended that session, not a late completion.
    pre-amendment— any `match: "tombstone_ref"` close overrides. Kept as the CONTROL: on a real
                   /clear stream it flips the killed call's final outcome from `aborted` to
                   `failed`, which is the door that reopened the false idle.

    analyze.py RUN_ROOT [RUN_ROOT ...]
"""
import sys

import stream


def ledger(events, cross_session_override=False):
    """D1 § 8.6, server side. Returns {call_id: record}, plus the late-completion count."""
    calls, late = {}, 0
    for e in events:
        kind, d = e.get("kind"), e.get("data") or {}
        cid = d.get("call_id")
        if kind == "tool.start":
            calls.setdefault(cid, _new(cid, d.get("tool_name"), d.get("agent_scope"),
                                       e.get("session_id")))
        elif kind == "tool.end":
            c = calls.setdefault(cid, _new(cid, d.get("tool_name"), None, e.get("session_id")))
            c["closes"].append({"outcome": d.get("outcome"), "abort_reason": d.get("abort_reason"),
                                "close_source": d.get("close_source"), "match": d.get("match"),
                                "session_id": e.get("session_id")})
            if c["state"] == "open":
                c.update(state="closed", outcome=d.get("outcome"),
                         abort_reason=d.get("abort_reason"), close_source=d.get("close_source"))
            elif d.get("match") == "tombstone_ref" and c["outcome"] == "aborted":
                same_session = e.get("session_id") == c["opened_in_session"]
                if same_session or cross_session_override:
                    late += 1
                    c.update(outcome=d.get("outcome"), abort_reason=d.get("abort_reason"),
                             close_source=d.get("close_source"), overridden_from="aborted")
                else:
                    c["cross_session_late_close_refused"] = True
    return calls, late


def _new(cid, tool, scope, session_id):
    return {"call_id": cid, "tool": tool, "scope": scope, "opened_in_session": session_id,
            "state": "open", "outcome": None, "abort_reason": None, "close_source": None,
            "closes": []}


def report(run_root):
    events = stream.load(run_root)
    print("=" * 100)
    print("RUN", run_root)
    for label, cross in (("amended (§ 8.6 + card#7337 Q2)", False),
                         ("pre-amendment CONTROL         ", True)):
        calls, late = ledger(events, cross_session_override=cross)
        print(f"  --- ledger: {label} ---")
        for c in calls.values():
            state = c["outcome"] or "NEVER CLOSED"
            print(f"    {c['call_id']}  {str(c['tool']):<6} scope={str(c['scope']):<9} "
                  f"FINAL outcome={state:<12} abort_reason={str(c['abort_reason']):<16} "
                  f"close_source={c['close_source']}")
            for i, cl in enumerate(c["closes"], 1):
                print(f"        close #{i}: outcome={cl['outcome']} abort_reason={cl['abort_reason']} "
                      f"close_source={cl['close_source']} match={cl['match']}")
            if c.get("overridden_from"):
                print(f"        ** § 8.6 late_completion OVERRIDE: {c['overridden_from']} -> {c['outcome']}")
            if c.get("cross_session_late_close_refused"):
                print("        ** cross-session late close REFUSED (Q2) — the abort stands")
        print(f"    late_completion overrides: {late}")
    tot, pred = stream.counters(run_root)
    interesting = {k: v for k, v in tot.items() if v and (
        "reap" in k or "tombstone" in k or "bind" in k or "clear" in k or "unmatched" in k)}
    if interesting:
        print("  --- the reporter's own counters (a reap that ran, and ran once) ---")
        for k, v in sorted(interesting.items()):
            print(f"    {k} = {v}")
        for k, v in sorted(pred.items()):
            if v and "clear" in k:
                print(f"    predicate {k} = {v}")
    print()


if __name__ == "__main__":
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    for r in sys.argv[1:]:
        report(r)

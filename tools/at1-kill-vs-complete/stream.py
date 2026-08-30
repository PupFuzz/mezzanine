#!/usr/bin/env python3
"""Reading a run's spool — the one loader the rest of the rig shares.

A spool line is D1 § 11.2's envelope: `{"v":1,"t":…,"e":{…}}`. The events are returned in
spool order with the spool's own `t` attached as `_t`.

This exists once, rather than in each of `analyze.py` / `derive.py` / `selftest.py`, because
two divergent readers of one format is how two of them come to disagree about what a run said.
"""
import glob
import json
import os


def load(run_root):
    """Every event of one run, oldest first."""
    ev = []
    for f in sorted(glob.glob(os.path.join(run_root, "spool", "*.jsonl"))):
        for ln in open(f, errors="replace"):
            ln = ln.strip()
            if not ln:
                continue
            try:
                d = json.loads(ln)
            except ValueError:
                continue          # a torn last line; D1 § 11.4's case, counted by the reporter
            e = d.get("e") or {}
            e["_t"] = d.get("t")
            ev.append(e)
    ev.sort(key=lambda e: e["_t"] or "")
    return ev


def counters(run_root):
    """The run's folded counter and predicate deltas (D1 § 11.1's counter sink)."""
    tot, pred = {}, {}
    for f in sorted(glob.glob(os.path.join(run_root, "spool", "counters", "*.jsonl"))):
        for ln in open(f, errors="replace"):
            ln = ln.strip()
            if not ln:
                continue
            try:
                d = json.loads(ln)
            except ValueError:
                continue
            for k, v in (d.get("c") or {}).items():
                tot[k] = tot.get(k, 0) + v
            for k, branches in (d.get("k") or {}).items():
                for b, v in branches.items():
                    pred[f"{k}.{b}"] = pred.get(f"{k}.{b}", 0) + v
    return tot, pred

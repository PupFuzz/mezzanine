#!/usr/bin/env python3
"""AT-1's RED half — make a deliberately defective copy of the reporter.

    plant.py --reporter PATH --out PATH --defect reap-disable

AT-1 says *run the RED, do not assume it*. A GREEN result is evidence only if the opposite
outcome was reachable, so the rig drives the same real `/clear` twice: once against the real
reporter and once against a copy with the § 8.3 reap removed.

**A plant that matches nothing RAISES.** A defect that failed to apply produces a run that
looks like a GREEN and is read as one — the exact failure mode `fleet-reporter.selftest.py`
guards for its own plants. Every defect below asserts its anchor occurs exactly once.

The copy is written OUTSIDE the repo tree (into the scratch root), so a defective reporter can
never be the one a seat installs.
"""
import argparse
import os
import shutil
import sys

DEFECTS = {
    # AT-1's first RED: "disable the reap in § 8.3 and re-run. The calls stay open until the
    # orphan timeout, the boundary events carry aborted_call_ids: [], and a consumer applying
    # only 'turn ended => idle' mints the false idle."
    "reap-disable": {
        "anchor": "  const victims = [...ix.calls.values()].filter(select).reverse();",
        "replacement": (
            "  /* AT-1 RED PLANT (tools/at1-kill-vs-complete/plant.py): the § 8.3 reap is\n"
            "   * DISABLED. Nothing is selected, so no open call is closed at a boundary and\n"
            "   * every boundary event carries aborted_call_ids: []. */\n"
            "  const victims = [];\n"
            "  if (false) [...ix.calls.values()].filter(select).reverse();"),
    },
}


def plant(reporter, out, defect):
    spec = DEFECTS[defect]
    src = open(reporter).read()
    n = src.count(spec["anchor"])
    if n != 1:
        raise SystemExit(
            f"plant '{defect}' FAILED: its anchor occurs {n} times in {reporter}, expected 1.\n"
            "The reporter moved under the plant. Re-derive the anchor rather than loosening it —\n"
            "a plant that quietly applies nowhere turns every RED run into a false GREEN.")
    os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
    open(out, "w").write(src.replace(spec["anchor"], spec["replacement"]))
    # selftest runs at install time and needs the fixtures beside the script (D1 § 2.1).
    fx_src = os.path.join(os.path.dirname(os.path.abspath(reporter)), "fixtures")
    fx_dst = os.path.join(os.path.dirname(os.path.abspath(out)), "fixtures")
    if os.path.isdir(fx_src) and not os.path.isdir(fx_dst):
        shutil.copytree(fx_src, fx_dst)
    return out


def main(argv=None):
    p = argparse.ArgumentParser(description="make a deliberately defective reporter copy")
    p.add_argument("--reporter", required=True)
    p.add_argument("--out", required=True)
    p.add_argument("--defect", required=True, choices=sorted(DEFECTS))
    a = p.parse_args(argv)
    if os.path.abspath(a.out).startswith(os.path.dirname(os.path.abspath(a.reporter))):
        raise SystemExit("refusing to write the defective copy beside the real reporter")
    print("planted", a.defect, "->", plant(a.reporter, a.out, a.defect))
    return 0


if __name__ == "__main__":
    sys.exit(main())

#!/usr/bin/env python3
"""The coordination-fixture guard's own controls — `docs/design/COORD-STORE.md § 3.5`'s selftest
(card#12239). Each plant is seen RED, then the committed tree GREEN.

  python3 tools/coord/synthesize-fixtures.selftest.py

Plants:
  --check  one edited byte in a generated file; one generated file deleted; one file added by hand
  --lint   a bare header line in a PHP test, a one-line quoted header literal in a second, and a
           delivery header name in a JS test — one per rule; a stale `(path, rule)` pair; a pair for a
           file that matches the OTHER rule only
  capture  `--out` inside a temp git repository; `git` absent; a signature header in the source
           (absent from the file written)

The planted strings are built from the spec's own vocabulary — the same templates the generator writes
from — so this file spells none of them and needs no exemption. The helper's refusal of an override
outside the spec is a PHP behaviour, held by `server/tests/Unit/Coord/DeriveTest.php`.
"""

import importlib.util
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
from contextlib import redirect_stdout
from io import StringIO
from pathlib import Path

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]


def load(name: str, file: str):
    spec = importlib.util.spec_from_file_location(name, HERE / file)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


syn = load("synthesize_fixtures", "synthesize-fixtures.py")
cap = load("capture_delivery", "capture-delivery.py")
SPEC = syn.load_spec()
failures = []


def quiet(fn, *a, **k):
    buf = StringIO()
    with redirect_stdout(buf):
        rc = fn(*a, **k)
    return rc, buf.getvalue()


def expect(label: str, want_rc: int, got_rc: int, out: str = ""):
    ok = (want_rc == 0) == (got_rc == 0)
    print(f"{'ok  ' if ok else 'FAIL'} {label} (rc {got_rc})")
    if not ok:
        failures.append(label)
        print("     " + out.strip().replace("\n", "\n     "))


def with_generated_copy(edit):
    """Run `--check` against a copy of the committed generated tree with `edit` applied."""
    saved = syn.GENERATED
    with tempfile.TemporaryDirectory() as tmp:
        copy = Path(tmp) / "generated"
        shutil.copytree(saved, copy)
        edit(copy)
        syn.GENERATED = copy
        try:
            return quiet(syn.check, SPEC)
        finally:
            syn.GENERATED = saved


# ── --check ─────────────────────────────────────────────────────────────────────────────────────────
def edit_byte(d: Path):
    f = sorted((d / "deliveries").glob("*.body.json"))[0]
    b = bytearray(f.read_bytes())
    b[5] = ord("x") if b[5] != ord("x") else ord("y")
    f.write_bytes(bytes(b))


expect("RED  --check: one edited byte in a generated file", 1, *with_generated_copy(edit_byte))
expect("RED  --check: one generated file deleted", 1,
       *with_generated_copy(lambda d: sorted((d / "rounds").glob("*.json"))[0].unlink()))
expect("RED  --check: one file added by hand", 1,
       *with_generated_copy(lambda d: (d / "rounds" / "hand-written.json").write_text("{}\n")))


# ── --lint ──────────────────────────────────────────────────────────────────────────────────────────
def lint_tree(files: dict, exemptions: str) -> tuple:
    with tempfile.TemporaryDirectory() as tmp:
        root = Path(tmp)
        subprocess.run(["git", "init", "-q", str(root)], check=True)
        for rel, text in files.items():
            p = root / rel
            p.parent.mkdir(parents=True, exist_ok=True)
            p.write_text(text, encoding="utf-8")
        ex = root / "exemptions.txt"
        ex.write_text(exemptions, encoding="utf-8")
        return quiet(syn.lint, SPEC, root=root, exemptions=ex)


from_line = SPEC["body_lines"]["from-line"].format(**{"from": "pm"})
to_line = SPEC["body_lines"]["to-line"].format(to="coder")
header_name = next(h["name"] for h in SPEC["headers"] if h["role"] == "delivery")

expect("GREEN --lint: a clean planted tree", 0, *lint_tree({"server/tests/Clean.php": "<?php // nothing\n"}, ""))
expect("RED  --lint `bare`: a header line in a temp PHP test", 1,
       *lint_tree({"server/tests/PlantBare.php": "<?php\n/*\n" + from_line + "\n*/\n"}, ""))
expect("RED  --lint `quoted`: a one-line literal in a second", 1,
       *lint_tree({"server/tests/PlantQuoted.php": "<?php $b = \"" + to_line + "\";\n"}, ""))
expect("RED  --lint `header`: a delivery header name in a temp JS test", 1,
       *lint_tree({"tools/plant.js": "const h = '" + header_name + "';\n"}, ""))
expect("GREEN --lint: the bare plant exempted by its (path, rule) pair", 0,
       *lint_tree({"server/tests/PlantBare.php": "<?php\n/*\n" + from_line + "\n*/\n"},
                  "server/tests/PlantBare.php bare # selftest\n"))
expect("RED  --lint: a stale pair for a file that no longer matches", 1,
       *lint_tree({"server/tests/Clean.php": "<?php\n"}, "server/tests/Clean.php bare # stale\n"))
expect("RED  --lint: a pair naming the rule its file does NOT match", 1,
       *lint_tree({"server/tests/PlantBare.php": "<?php\n/*\n" + from_line + "\n*/\n"},
                  "server/tests/PlantBare.php bare # right\nserver/tests/PlantBare.php quoted # the other rule\n"))
expect("RED  --lint: the population reaches a shell file under bin/ as well as the test tree", 1,
       *lint_tree({"bin/plant.sh": from_line + "\n"}, ""))


# ── capture ─────────────────────────────────────────────────────────────────────────────────────────
signature = next(h["name"] for h in SPEC["headers"] if h["role"] == "signature-256")
record = {"request": {"headers": {signature: "sha256=" + "0" * 64, "Content-Type": "application/json"},
                      "payload": {"action": "created", "issue": {"number": 1}}}}

with tempfile.TemporaryDirectory() as tmp:
    src = Path(tmp) / "record.json"
    src.write_text(json.dumps(record))

    repo = Path(tmp) / "repo"
    subprocess.run(["git", "init", "-q", str(repo)], check=True)
    rc, out = quiet(cap.main, ["--in", str(src), "--out", str(repo / "capture.json")])
    expect("RED  capture: --out inside a temp git repository is refused", 1, rc, out)
    expect("     … and nothing was written there", 0, 0 if not (repo / "capture.json").exists() else 1)

    nogit = Path(tmp) / "nogit"
    nogit.mkdir()
    old = os.environ.get("PATH", "")
    os.environ["PATH"] = str(Path(tmp) / "empty-path")
    try:
        rc, out = quiet(cap.main, ["--in", str(src), "--out", str(nogit / "capture.json")])
    finally:
        os.environ["PATH"] = old
    expect("RED  capture: git absent is refused, never read as outside", 1, rc, out)

    outside = Path(tmp) / "outside"
    outside.mkdir()
    rc, out = quiet(cap.main, ["--in", str(src), "--out", str(outside / "capture.json")])
    expect("GREEN capture: a path outside every worktree is written", 0, rc, out)
    written = (outside / "capture.json").read_text()
    expect("     … with the signature header absent from the file", 0, 1 if signature in written else 0)
    expect("     … at mode 0600", 0, 0 if stat.S_IMODE((outside / "capture.json").stat().st_mode) == 0o600 else 1)
    expect("     … printing no value", 0, 1 if "sha256=" in out else 0, out)


# ── the committed tree ──────────────────────────────────────────────────────────────────────────────
expect("GREEN --check over the committed generated tree", 0, *quiet(syn.check, SPEC))
expect("GREEN --lint over the committed tree with exactly the listed pairs", 0, *quiet(syn.lint, SPEC))

print(f"\n{'ALL CHECKS PASS' if not failures else str(len(failures)) + ' FAILED: ' + '; '.join(failures)}")
sys.exit(1 if failures else 0)

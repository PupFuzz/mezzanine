# ── VENDORED — DO NOT EDIT ANYTHING BELOW THIS HEADER ─────────────────────────────────────────
#
# _operator_registry.py — the operator registry's primitives, imported by `coord-ask-operator`.
# Everything below this header is upstream's file, byte for byte, EXCEPT THE DECLARED SITES BELOW,
# and `bin/vendor-pin-check.sh` pins its body.
#
#   DECLARED LOCAL EDITS — this is the COMPLETE list. Anything else below is a defect.
#     none.
#
#   upstream repo     PupFuzz/agent-board-framework  (PRIVATE — a public CI runner cannot clone it)
#   upstream path     plugins/coord/templates/bin/_operator_registry.py
#   vendored from     afaf9a10022fbcab33287155076b1267b422fce4   (marketplace, the v0.77.0 release merge)
#   plugin version    coord 0.77.0
#   upstream sha256   55571560eaaa204d727357dea9d68b59f34d32f041e14fd88f34849fcf80df4f
#                     — the WHOLE upstream file, NOT the body figure vendor-pin-check pins.
#
# WHY THIS REPOSITORY CARRIES IT. Nothing here files operator questions: this repository has no
# operator registry. `bin/pr-body-lint.selftest.py` arm M holds the lint's `DESIGN_APPROVED_ID_RE`
# equal to `coord-ask-operator`'s `ITEM_ID_RE`, character for character, and REDS when
# `coord-ask-operator` is not beside it; `coord-ask-operator` imports `_operator_registry.py` from
# its own directory at load, so both are here. Re-vendor them with `bin/pr-body-lint.py`, from the
# same upstream commit.
#
# WHY THIS HEADER EXISTS — `bin/vendor-pin-check.sh --selftest`'s control arm requires line 2 to
# be a `#` comment and upstream's file opens on its docstring, with no shebang. `bin/pr-body-lint.py`'s header states the
# measurement; not restated here.
#
# ── END OF MEZZANINE'S HEADER ─────────────────────────────────────────────────────────────────
"""_operator_registry.py — the operator registry's primitives, ONE copy each (card#12554).

    registry_root  resolve_role  registry_lock  append_jsonl  read_jsonl  jsonl_line  ledger_root

WHY THIS FILE EXISTS. Three tools read and write the operator registry's streams: the filer
(`coord-ask-operator`), the register (`operator-register.py`) and the ledger tool
(`operator-ledger.py`). Until card#12554 the primitives lived in the first two, and the ledger
tool loaded both as siblings to reach them (canon #5 — one appender, one reader). card#12554 puts
the ledger tool on an IMPL seat too, where neither sibling is installed (they are `pm, solo`
rows), so the primitives move here — a library the three of them import — and nothing is copied.
`docs/design/2026-10-28-card12554-waypoint-shared-project-memory.md` § 3 owns the decision.

THE FILER AND THE REGISTER RE-EXPORT WHAT MOVED. Each still exposes every name it exposed before
(`from _operator_registry import …` at module level binds them as that module's attributes), so
every caller that loads one of them as a sibling and checks `hasattr` keeps working unchanged:
`operator-register.py`'s `_NEED_ASK_OPERATOR` / `_NEED_RENDER` / `_NEED_INDICATOR` /
`_NEED_APPLY`, and the ledger's own former `_NEED_*`. `registry_lock` and `jsonl_line` moved with
`append_jsonl` because `coord-ask-operator --edit` takes the same lock and serialization.

`registry_root()` IS NOT WIDENED, and `ledger_root()` sits beside it. The filer's refusal of an
impl seat IS `registry_root()`'s impl branch, and other tools rest on "an impl seat has no
registry". The ledger is a different question — where THIS seat's record of the operator's words
lives — so it has its own answer: the registry root on a pm or solo seat, and the solo shape
(`~/.config/coord/operator-register/`) on an impl seat. Every claim about the REGISTER stays true.

PACKAGING. Installed BESIDE its three importers in `~/.local/bin` (the `coord_attribution.py`
convention); each importer puts its own directory first on `sys.path` before importing it.
Standard library only; nothing runs at import.
"""
import json
import os


def resolve_role():
    """This machine's roster role (`pm` | `impl` | `solo`), or `None` when it is unknowable.

    The same read `hooks/bin/_coordcfg.resolve_role()` performs — the identity env names the
    seat, the config's roster says what that seat is. It is repeated here rather than imported
    because this file deploys as a per-file copy into `~/.local/bin`, where nothing of
    `hooks/bin/` exists; that is the island boundary `sprint-burndown.py` already crosses the
    same way with its own `config_path`/`load_config`.

    FAIL-CLOSED HERE, unlike `_coordcfg`'s fail-open. `_coordcfg`'s callers degrade to
    role-neutral behaviour and a wrong guess costs a less specific answer. This one's caller picks
    a DIRECTORY TO WRITE INTO, and a guess there files an item where nothing will ever look for
    it. `None` therefore reaches `registry_root()` as a refusal, never as a default."""
    agent = os.environ.get("COORD_AGENT", "")
    path = (os.environ.get("COORD_CONFIG") or "").strip()
    if not agent or not path:
        return None
    try:
        with open(os.path.expanduser(path), encoding="utf-8") as fh:
            cfg = json.load(fh)
        return next((r.get("role") for r in cfg.get("roster", []) if r.get("name") == agent), None)
    except Exception:                                     # noqa: BLE001 — reason is the output
        return None


SOLO_ROOT = "~/.config/coord/operator-register"

_UNRESOLVED_ROLE = ("this machine's roster role could not be resolved, so there is no registry to "
                    "file into. $COORD_AGENT names the seat and $COORD_CONFIG names the config "
                    "whose `roster` says what that seat is — one of them is unset, unreadable, or "
                    "does not list this seat. Fix that, or pass --root to name the directory "
                    "explicitly. Nothing was written: a guessed directory is a filed item nobody "
                    "ever renders.")


def registry_root(explicit=None):
    """`(path, None)` or `(None, why_not)` — the directory this install's registry lives in.

    ⛔ THIS IS THE WHOLE OF THE PM-VS-SOLO DIFFERENCE, AND IT IS ONE CONDITIONAL. A PM install has
    a coordination repo and the registry is PM STATE, beside `STATE/decisions-log.md`. A solo
    install has no coordination repo at all, so the registry sits beside the config that already
    identifies that install. Every other line of this program reads and writes only through the
    root this returns and cannot tell which branch it took.

    ⛔ AN IMPL SEAT IS REFUSED, BY NAME. An impl agent has no operator channel — its questions go
    to its PM, through the coordination protocol that already exists for exactly that. Filing here
    would write a record into a registry no operator-facing surface on that machine renders, and
    the agent would read "filed" as "asked". (The operator LEDGER is a different question with its
    own answer — `ledger_root()`.)"""
    if explicit:
        return os.path.abspath(os.path.expanduser(explicit)), None
    role = resolve_role()
    if role == "solo":
        return os.path.expanduser(SOLO_ROOT), None
    if role == "pm":
        repo = os.path.dirname(os.path.abspath(os.path.expanduser(os.environ["COORD_CONFIG"])))
        return os.path.join(repo, "STATE", "operator-register"), None
    if role == "impl":
        return None, ("this seat's roster role is `impl`, and an impl seat has no operator "
                      "channel: send this question to your PM on the coordination thread it "
                      "belongs to. `coord-ask-operator` files for PM and solo seats only.")
    return None, _UNRESOLVED_ROLE


def ledger_root(explicit=None):
    """`(path, None)` or `(None, why_not)` — the directory THIS seat's operator LEDGER lives in.

    `registry_root()`'s answer on a pm or solo seat (the ledger is the registry's input stream
    there, so it lives beside the other streams), and the solo shape on an impl seat: an impl seat
    the operator talks to in chat records what it was told on its own machine (card#12554 § 5),
    without becoming a seat that FILES — `registry_root()` still refuses it. An unresolvable role
    is refused by name, as there."""
    if not explicit and resolve_role() == "impl":
        return os.path.expanduser(SOLO_ROOT), None
    return registry_root(explicit)


def jsonl_line(record):
    """One record's serialization, shared by the appender and the in-place edit."""
    return json.dumps(record, ensure_ascii=False, sort_keys=True)


class registry_lock:
    """`with registry_lock(path):` — an EXCLUSIVE lock on the registry directory holding `path`.

    THE ONE LOCK EVERY REGISTRY WRITE TAKES: `append_jsonl` (every filing, and every record
    `operator-register.py apply` writes, which calls it) and `edit_record`'s read-modify-replace.
    An append alone is one `write` on an append-mode handle and cannot tear another; what it CAN
    do is land between an edit's read and its `os.replace`, which would then overwrite it — so the
    edit and the append must exclude each other, and they do by sharing this lock.

    The lock is a separate file, `registry.lock`, in the stream's directory — never the stream
    itself, because `os.replace` swaps the stream's inode, and a lock held on the old one would not
    stop a writer that opened the new one. It is empty and never read. Blocking: POSIX `flock`
    waits; Windows `msvcrt.locking(LK_LOCK)` retries for about ten seconds and then raises
    `OSError`, which the caller reports as a failed write.

    ⛔ `fcntl` / `msvcrt` ARE IMPORTED ON ENTRY, NOT AT MODULE LEVEL: `operator-register.py
    --indicator` executes this file on every assistant message and never writes."""

    def __init__(self, path):
        self.path = os.path.join(os.path.dirname(os.path.abspath(path)), "registry.lock")
        self.fh = None

    def __enter__(self):
        os.makedirs(os.path.dirname(self.path), exist_ok=True)
        self.fh = open(self.path, "a+b")
        try:
            if os.name == "nt":
                import msvcrt
                self.fh.seek(0)
                msvcrt.locking(self.fh.fileno(), msvcrt.LK_LOCK, 1)
            else:
                import fcntl
                fcntl.flock(self.fh.fileno(), fcntl.LOCK_EX)
        except BaseException:
            self.fh.close()
            raise
        return self

    def __exit__(self, *exc):
        try:
            if os.name == "nt":
                import msvcrt
                self.fh.seek(0)
                msvcrt.locking(self.fh.fileno(), msvcrt.LK_UNLCK, 1)
            # POSIX: closing the descriptor releases the `flock`.
        finally:
            self.fh.close()
        return False


def append_jsonl(path, record):
    """Append one JSON object as one line to `path`. THE ONE APPENDER OF EVERY REGISTRY STREAM.

    `filed.jsonl` (the filer), `resolved.jsonl`, `deferred.jsonl` and `applied-posts.jsonl`
    (`operator-register.py apply --from-paste`), `withdrawn.jsonl` (`operator-register.py`'s
    `record_withdrawals`, run by `--html`, `--json` and `apply`) and `operator-ledger.jsonl`
    (`operator-ledger.py`) — each calls this function rather than keeping a second copy of it: one
    serialization, so a record one tool writes cannot differ in shape from one another writes.

    The only other write to a stream is `edit_record`'s in-place rewrite of one `filed` line, and
    both take `registry_lock()`, so an append can never land between an edit's read and its replace
    and be overwritten by it. Inside the lock the record is still ONE `write` call on an append-mode
    handle: a record assembled in memory and emitted in a single append cannot interleave with
    another writer's and leave two half-lines neither of which parses.

    ⛔ A STREAM WHOSE LAST LINE HAS NO NEWLINE GETS ONE BEFORE THE NEW RECORD. That last line is a
    torn write (a crash mid-append) or a hand edit, and appending straight onto it glues the new
    record to it — two records lost into one line nobody can parse, the second of them written
    whole a moment ago. With the newline the torn line stays exactly as broken as it was, where
    the readers report it by line number, and the new record is its own line."""
    with registry_lock(path):
        lead = ""
        if os.path.exists(path) and os.path.getsize(path):
            with open(path, "rb") as fh:
                fh.seek(-1, os.SEEK_END)
                if fh.read(1) != b"\n":
                    lead = "\n"
        line = lead + jsonl_line(record) + "\n"
        with open(path, "a", encoding="utf-8", newline="\n") as fh:
            fh.write(line)
    return path


def read_jsonl(path):
    """`(rows, fault)` — `[(lineno, obj_or_None, raw)]` and a stream-level reason, or `(rows, None)`.

    ⛔ THREE OUTCOMES, AND THEY ARE THREE. A stream that does not exist is EMPTY and that is a
    measurement: a stream nobody has written to yet is legitimately empty — `resolved.jsonl`
    on an install whose operator has not answered anything yet. A stream that exists and cannot be
    READ is a FAULT — the caller makes the whole run UNMEASURED, because an unreadable
    `resolved.jsonl` would otherwise render every answered item as still open and send the operator
    back through questions they have already answered. A LINE that does not parse is neither, and
    it is NEVER dropped: it comes back as `obj None` with its raw text, and the caller decides what
    it costs. `derive()` reports it as its own unmeasured item, because a dropped line is a question
    nobody ever gets asked; `apply` refuses to write at all over one in its journal or in an answer
    stream, because a torn line there is a fact about what was already posted or answered that
    nobody can read.

    ⛔ SPLIT ON LF ONLY, NEVER `str.splitlines()`. Every writer serializes with
    `ensure_ascii=False`, so a record whose text carries U+2028/U+2029 (pasted from a web page or a
    word processor) holds those characters RAW, and `splitlines()` splits on them — one record read
    back as two torn lines. The universal-newline read already turns a CRLF (or a lone CR) into
    an LF. This is also `coord-ask-operator --edit`'s reader (it rewrites the lines it read), so a
    wrong split here would not merely misreport a record, it would write it back torn."""
    if not os.path.exists(path):
        return [], None
    try:
        with open(path, encoding="utf-8") as fh:
            raw_lines = fh.read().split("\n")
    except Exception as e:                                    # noqa: BLE001 — reason is the output
        return [], "%s could not be read (%s: %s)" % (path, type(e).__name__, str(e)[:200])
    rows = []
    for n, raw in enumerate(raw_lines, 1):
        if not raw.strip():
            continue
        try:
            obj = json.loads(raw)
        except Exception:                                     # noqa: BLE001
            obj = None
        rows.append((n, obj if isinstance(obj, dict) else None, raw))
    return rows, None

#!/usr/bin/env python3
"""Raw hook-payload capture — one O_APPEND write per hook fire.

Wired beside the reporter on every hook, so the rig holds evidence of what the HARNESS fired
that does not depend on the reporter under test. When the question is "did the reporter miss a
close hook or did the harness never send one", a capture written by the reporter cannot answer
it.

Contract: hook name as argv[1], payload on stdin, destination in $CAPTURE_FILE. Exits 0 and
prints nothing, always — a hook that writes to stdout injects text into the driven session
(D1 § 2.2, P-1/P-2).
"""
import json
import os
import sys
import time

name = sys.argv[1] if len(sys.argv) > 1 else "UNKNOWN"
raw = sys.stdin.read()
now = time.time()
line = json.dumps({
    "_hook": name,
    "_at": time.strftime("%Y-%m-%dT%H:%M:%S", time.gmtime(now)) + ".%03dZ" % (int(now * 1000) % 1000),
    "_raw": raw,
}, ensure_ascii=False) + "\n"
fd = os.open(os.environ["CAPTURE_FILE"], os.O_WRONLY | os.O_CREAT | os.O_APPEND, 0o600)
os.write(fd, line.encode())
os.close(fd)

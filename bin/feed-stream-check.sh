#!/usr/bin/env bash
# feed-stream-check.sh — the R1 WIRE check of `docs/design/FLEET-STATE.md § 8.3`, run by an OPERATOR
# against a deployed origin. card#9300.
#
# WHY IT IS NOT A DEPLOY STEP. It needs a stream opened with a signed-in MFA session — § 9 refuses the
# stream to every machine credential, so a deploy has nothing to present — and it cannot run inside the
# deploy's maintenance window anyway. `docs/PLAN.md § 5` puts it in the runbook: after any deploy that
# changed the stream path, the proxy, or the pool.
#
# WHAT IT ASSERTS — timing, never a count over a fixed window (heartbeats every 15 s make a fixed window
# hold 8 or 9, phase-dependent):
#   1. the response is `text/event-stream` with NO `Content-Encoding` — an encoding means something
#      between PHP-FPM and the browser compresses the stream;
#   2. the handler's FIRST frame, `fleet.health`, arrives within FIRST_FRAME_S of the request — it is the
#      stream's first byte, so a proxy that buffers holds exactly it;
#   3. `feed.heartbeat` frames arrive, and no gap before or between them exceeds GAP_S.
# It sends `Accept-Encoding` EXPLICITLY, as a browser does: a proxy that gzips text/* stays dormant for a
# bare curl, which would then pass while every browser renders F19.
#
# MEASURED BEFORE IT WAS TRUSTED (2026-09-13, the sandbox host): against a throwaway non-root Apache 2.4
# with this host's Virtualmin vhost shape (`SetHandler proxy:unix:…|fcgi://…`) in front of a throwaway
# php-fpm8.5 serving this route — PASS with `ProxySet flushpackets=on` on the FPM socket's <Proxy>; FAIL
# with the shape as Virtualmin writes it (mod_proxy_fcgi held the frames until the request ended); FAIL
# with `AddOutputFilterByType DEFLATE text/event-stream`; PASS again with flushpackets on.
#
# USAGE
#   bin/feed-stream-check.sh <origin> <cookie-header-file>
#     <origin>              e.g. https://mezzanine.example — the stream is <origin>/api/fleet/stream
#     <cookie-header-file>  a file holding ONE line, `Cookie: <session cookie name>=<value>`, copied from a
#                           browser signed in with MFA. Mode 600: the session is a live credential, and
#                           this script refuses a file others can read. It is never passed in argv.
#   Environment: LISTEN_S (default 50) · FIRST_FRAME_S (default 2) · GAP_S (default 20)
#
# EXIT: 0 every assertion held · 1 at least one did not (each named) · 2 bad invocation

set -uo pipefail

ORIGIN="${1:-}"; COOKIE_FILE="${2:-}"
LISTEN_S="${LISTEN_S:-50}"; FIRST_FRAME_S="${FIRST_FRAME_S:-2}"; GAP_S="${GAP_S:-20}"
if [ -z "$ORIGIN" ] || [ -z "$COOKIE_FILE" ]; then
  sed -n '/^# USAGE/,/^# EXIT/p' "$0" | sed 's/^# \{0,1\}//' >&2; exit 2
fi
[ -r "$COOKIE_FILE" ] || { echo "cannot read $COOKIE_FILE" >&2; exit 2; }
case "$(stat -c '%a' "$COOKIE_FILE")" in
  ?00) ;;
  *) echo "refusing: $COOKIE_FILE is readable by others (mode $(stat -c '%a' "$COOKIE_FILE")) — it holds a live session; chmod 600 it" >&2; exit 2 ;;
esac
for c in curl python3; do command -v "$c" >/dev/null 2>&1 || { echo "missing: $c" >&2; exit 2; }; done

work="$(mktemp -d)"; trap 'rm -rf "$work"' EXIT
start="$(date +%s.%N)"
curl -sS -N --max-time "$LISTEN_S" -D "$work/headers" \
  -H 'Accept: text/event-stream' -H 'Accept-Encoding: gzip, deflate, br' -H "@$COOKIE_FILE" \
  "${ORIGIN%/}/api/fleet/stream" 2> "$work/curl.err" \
  | while IFS= read -r line; do printf '%s %s\n' "$(date +%s.%N)" "$line"; done > "$work/lines"

python3 - "$work/headers" "$work/lines" "$start" "$FIRST_FRAME_S" "$GAP_S" "$LISTEN_S" <<'PY'
import json, sys
headers, lines, start, first_s, gap_s, listen_s = sys.argv[1], sys.argv[2], float(sys.argv[3]), float(sys.argv[4]), float(sys.argv[5]), float(sys.argv[6])
fails = []
hdr = [h.strip() for h in open(headers, errors="replace").read().splitlines() if h.strip()]
status = next((h for h in hdr if h.upper().startswith("HTTP/")), "<no status line>")
ctype = next((h.split(":", 1)[1].strip() for h in hdr if h.lower().startswith("content-type:")), "")
enc = next((h.split(":", 1)[1].strip() for h in hdr if h.lower().startswith("content-encoding:")), "")
print(f"status: {status}")
if not hdr:
    fails.append(f"NOT EVEN THE RESPONSE HEADERS arrived in {listen_s:.0f} s — something between PHP-FPM and this client holds the whole response (§ 8.3 R1); a 401/403 would have arrived at once")
elif " 200" not in status:
    fails.append(f"the stream was not served ({status}) — a 401/403 is the cookie (sign in with MFA and copy it again)")
elif not ctype.startswith("text/event-stream"):
    fails.append(f"Content-Type is '{ctype}', not text/event-stream")
if enc:
    fails.append(f"Content-Encoding: {enc} — something between PHP-FPM and the browser compresses the stream (§ 8.3 R1)")
frames = []
for raw in open(lines, errors="replace").read().splitlines():
    t, _, rest = raw.partition(" ")
    if rest.startswith("data:"):
        try:
            frames.append((float(t) - start, json.loads(rest[5:].strip()).get("t")))
        except Exception:
            frames.append((float(t) - start, "<not JSON>"))
if not frames:
    if hdr:
        fails.append(f"NO frame arrived in {listen_s:.0f} s — the stream opened and never spoke: a buffering proxy (FLOOR.md § 9 F19)")
else:
    first_t, first_type = frames[0]
    print(f"first frame: {first_type} at +{first_t:.2f} s")
    if first_type != "fleet.health":
        fails.append(f"the first frame is '{first_type}', not the handler's on-connect fleet.health")
    if first_t > first_s:
        fails.append(f"the first frame arrived at +{first_t:.2f} s, later than {first_s:.0f} s — something held the stream's first byte (§ 8.3 R1)")
    beats = [t for t, kind in frames if kind == "feed.heartbeat"]
    print("heartbeats at: " + (", ".join(f"+{t:.1f}s" for t in beats) or "none"))
    if len(beats) < 2:
        fails.append(f"{len(beats)} feed.heartbeat frame(s) in {listen_s:.0f} s — is mezzanine:feed-heartbeat running on this host? (FLEET-STATE.md § 2.1)")
    gaps = [b - a for a, b in zip([first_t] + beats, beats)]
    wide = [g for g in gaps if g > gap_s]
    if wide:
        fails.append(f"a heartbeat gap of {max(wide):.1f} s exceeds {gap_s:.0f} s — heartbeats are written every 15 s, so frames are being held")
    closes = [kind for _, kind in frames if kind == "feed.close"]
    if closes:
        print("the stream was closed by the server during the check (feed.close) — re-run it")
for f in fails:
    print("FAIL " + f)
print("PASS — the stream reached this client as it was written (R1's wire half)" if not fails else f"{len(fails)} R1 wire assertion(s) failed")
sys.exit(1 if fails else 0)
PY
rc=$?
if [ -s "$work/curl.err" ] && ! grep -q 'Operation timed out' "$work/curl.err"; then sed 's/^/curl: /' "$work/curl.err"; fi
exit "$rc"

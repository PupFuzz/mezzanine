'use strict';
/* fixed-clock.cjs — the acceptance suites' test clock (card#12631). A HARNESS FILE, NEVER INSTALLED: it
 * is not in `install.js`'s artifact list, and `fleet-reporter.js` does not know it exists.
 *
 * WHY. `FLEET_REPORTER_NOW_MS` sets the instant a reporter process STARTS at; real time then keeps running
 * inside the process, so every pinned clock read lands some unknown time after the pin — milliseconds on an
 * idle machine, seconds on a loaded one. A case that crosses a window (K.CLEAR_GAP_MS, a heartbeat interval,
 * an hour boundary) was therefore a race against the host's load. This file is loaded into the reporter
 * process ahead of the script (`NODE_OPTIONS=--require <this file>`, so a flusher a hook forks inherits it
 * too) and makes the reporter's clock one the test controls: time moves only when the test moves it.
 *
 * WHAT IT REPLACES. `Date.now()` — the reporter's one clock read; `now()` and the `FLEET_REPORTER_NOW_MS`
 * offset are both built on it. The reporter reads no other clock: no `new Date()` without an argument, no
 * `performance.now`, no `process.hrtime`, no `setInterval` or `setImmediate`. § 0 of fleet-reporter.selftest.py
 * asserts that inventory against the source, and its timers' (below), so a new clock read or timer reds there
 * rather than escaping this file.
 *
 * WHOSE READS. Only a read made BY THE REPORTER SCRIPT (the caller frame's file is the process's main
 * script, `process.argv[1]`), and not by the functions in WALL_CLOCK_READERS: the seat write lock deliberately measures a
 * lock file's age, and its own wait, on the filesystem's clock (the reporter's header over
 * `sleepSync`), so those keep real time. Node's own internals keep real time as well.
 *
 * THE MODES, from `FLEET_REPORTER_TEST_CLOCK`:
 *   unset or `wall` — inert: nothing is replaced.
 *   `fixed`         — the reporter's clock reads ONE instant for the life of the process:
 *                     `FLEET_REPORTER_NOW_MS` when it is set, else the wall clock at process start.
 *                     For one-shot processes: a hook, the statusline, `selftest`, a one-pass flusher.
 *                     Timers stay real here: a request deadline still waits real time, so a case about
 *                     WHICH deadline a request waits out uses `file:` instead.
 *   `file:<path>`   — the clock reads the integer milliseconds in <path>, re-read at every read, so a
 *                     long-running flusher's time moves only when the test rewrites the file. With
 *                     `FLEET_REPORTER_NOW_MS` set the process still STARTS at that instant and moves by
 *                     what the file moves. The reporter's timers (`setTimeout`/`clearTimeout`: its pass
 *                     loops' naps and its request deadlines) are driven by the same clock: one fires when
 *                     the file's clock reaches its due time, never on its own. After every change this
 *                     file writes <path>.ack — the clock it last acted on, every live timer (its due
 *                     time, the reporter function that set it, its callback's first characters), and
 *                     whether a hook is blocked reading its stdin — so the test can wait for a quiet
 *                     process instead of sleeping (`TestClock` in fleet-reporter.selftest.py).
 * A clock file that cannot be read as an integer ends the process with exit 86 and a line on stderr: a
 * clock silently falling back to wall time would make every verdict after it a race again. */
const fs = require('fs');
const path = require('path');

const MODE = process.env.FLEET_REPORTER_TEST_CLOCK || 'wall';
const WALL_CLOCK_READERS = new Set(['breakStaleWriteLock', 'acquireWriteLock']);
module.exports = { WALL_CLOCK_READERS };

if (MODE !== 'wall') install();

function install() {
  const realDateNow = Date.now;
  // Node names the main module by its real path (symlinks resolved), so the caller frame is compared with that.
  let MAIN = null;
  if (process.argv[1]) { try { MAIN = fs.realpathSync(path.resolve(process.argv[1])); } catch (e) { MAIN = path.resolve(process.argv[1]); } }
  const die = (msg) => { try { process.stderr.write(`fixed-clock: ${msg}\n`); } catch (e) { /* nothing */ } process.exit(86); };

  // The caller frames of a replaced function, as V8 call sites, without the replacement's own frame.
  const frames = (fn) => {
    const prev = Error.prepareStackTrace, prevLimit = Error.stackTraceLimit;
    Error.prepareStackTrace = (_e, cs) => cs;
    Error.stackTraceLimit = 8;
    const o = {};
    Error.captureStackTrace(o, fn);
    const cs = o.stack;
    Error.prepareStackTrace = prev; Error.stackTraceLimit = prevLimit;
    return Array.isArray(cs) ? cs : [];
  };
  const fromMain = (f) => !!f && MAIN !== null && f.getFileName() === MAIN;

  let clock;   // () => the test clock's instant, ms
  let file = null;
  if (MODE === 'fixed') {
    const pin = Number(process.env.FLEET_REPORTER_NOW_MS);
    const at = process.env.FLEET_REPORTER_NOW_MS && Number.isFinite(pin) ? pin : realDateNow();
    clock = () => at;
  } else if (MODE.startsWith('file:')) {
    file = MODE.slice(5);
    clock = () => {
      let v;
      try { v = fs.readFileSync(file, 'utf8').trim(); } catch (e) { die(`cannot read the clock file ${file}: ${e.code}`); }
      if (!/^-?\d+$/.test(v)) die(`the clock file ${file} holds no integer`);
      return Number(v);
    };
  } else {
    die(`unknown FLEET_REPORTER_TEST_CLOCK mode ${JSON.stringify(MODE)}`);
  }

  Date.now = function now() {
    const f = frames(now)[0];
    if (fromMain(f) && !WALL_CLOCK_READERS.has(f.getFunctionName() || '')) return clock();
    return realDateNow();
  };

  if (file !== null) fakeTimers(file, clock, frames, fromMain);
}

/* The reporter's timers on the file's clock. A timer is due at (clock when set) + delay and fires on the
 * first poll that reads the file at or past that instant, in due order; a real interval polls the file,
 * referenced only while a timer is pending, so the process lives exactly as long as it would on real
 * timers. Timers set by anything but the reporter script are passed through to the real ones. */
function fakeTimers(file, clock, frames, fromMain) {
  const real = { setTimeout: global.setTimeout, clearTimeout: global.clearTimeout, setInterval: global.setInterval };
  const timers = new Map();   // id -> { due, seq, cb, args, by, ms }
  let nextId = 1, seq = 0, acted = clock(), stdin = null;
  const ack = () => {
    const live = [...timers.entries()].sort((a, b) => a[1].due - b[1].due || a[1].seq - b[1].seq)
      .map(([id, t]) => ({ id, due: t.due, ms: t.ms, by: t.by, src: t.src }));
    const tmp = `${file}.ack.${process.pid}`;
    try { fs.writeFileSync(tmp, JSON.stringify({ pid: process.pid, clock: acted, timers: live, stdin })); fs.renameSync(tmp, `${file}.ack`); }
    catch (e) { /* the spool's temp dir went away at the end of a case */ }
  };
  // A hook blocks reading its payload on fd 0. `stdin: "reading"` in the ack says it has got that far — its
  // module loaded and every clock read before its payload made — so a case holding a hook on its stdin can
  // move the clock knowing which side of the move those reads fell on.
  const realReadFileSync = fs.readFileSync;
  fs.readFileSync = function readFileSync(p, ...rest) {
    if (p !== 0) return realReadFileSync.call(fs, p, ...rest);
    stdin = 'reading'; ack();
    try { return realReadFileSync.call(fs, p, ...rest); } finally { stdin = 'read'; ack(); }
  };
  // The first reporter function on the stack names what set the timer (`nap`, `ingestRequest`).
  const setter = (fs_) => { for (const f of fs_) { const n = fromMain(f) && f.getFunctionName(); if (n) return n; } return '?'; };
  let poller = null;
  const refPoll = () => { if (poller) { if (timers.size) poller.ref(); else poller.unref(); } };
  const tick = () => {
    const c = clock();
    let fired = c !== acted;
    acted = c;
    for (;;) {
      let pick = null;
      for (const [id, t] of timers) if (t.due <= c && (!pick || t.due < pick[1].due || (t.due === pick[1].due && t.seq < pick[1].seq))) pick = [id, t];
      if (!pick) break;
      timers.delete(pick[0]);
      fired = true;
      pick[1].cb(...pick[1].args);
    }
    refPoll();
    if (fired) ack();
  };
  global.setTimeout = function setTimeout(cb, ms, ...args) {
    const fs_ = frames(setTimeout);
    if (!fromMain(fs_[0])) return real.setTimeout(cb, ms, ...args);
    const id = nextId++;
    const delay = Math.max(1, Number(ms) || 0);
    // `src`, the callback's first characters, tells a request's two deadlines apart (`'request'`, `'connect'`).
    timers.set(id, { due: clock() + delay, seq: seq++, cb, args, by: setter(fs_), ms: delay, src: String(cb).slice(0, 80) });
    refPoll(); ack();
    return { __fakeTimer: id, ref() { return this; }, unref() { return this; }, hasRef() { return true; } };
  };
  global.clearTimeout = function clearTimeout(t) {
    if (t && typeof t === 'object' && t.__fakeTimer) { if (timers.delete(t.__fakeTimer)) { refPoll(); ack(); } return; }
    return real.clearTimeout(t);
  };
  poller = real.setInterval(tick, 5);
  refPoll();
  ack();
}

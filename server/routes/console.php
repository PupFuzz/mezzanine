<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * `docs/design/FLEET-STATE.md § 2.1`'s **purge** process — HOURLY, its stated cadence.
 *
 * The other two long-lived processes (`mezzanine:fold`, `mezzanine:sweep`) are NOT here and must
 * not be: § 2.1 gives them a SUPERVISOR, not a schedule, because they are continuous loops with
 * their own poll intervals (≤ 1 s and 15 s). A scheduler entry for either would start a second
 * copy every minute of a daemon that is already running.
 *
 * `withoutOverlapping()` because § 6.7 gives one pass a **60-second wall-clock budget** and the
 * cadence is hourly: a pass that hits its budget has, by definition, more to do, and a second
 * process entering the same bounded-batch DELETE loop would double the store's write load at
 * exactly the moment it is already behind. The purge is idempotent — it deletes what is past
 * retention — so overlapping is not a correctness problem; it is the wrong response to the one
 * condition that could produce it.
 */
Schedule::command('mezzanine:purge')->hourly()->withoutOverlapping();

/*
 * `docs/design/FLEET-STATE.md § 2.1`'s **board poll** process — every 5 minutes, the cadence
 * `docs/design/BOARD-TASK.md § 3.2` settles. A schedule entry and not a supervised daemon, for the
 * reason the purge entry above gives: one bounded read per configured board, then one transaction.
 *
 * `withoutOverlapping()` so a slow board does not start a second poll beside the first — and with
 * an EXPIRY, which § 3.1 makes part of the design rather than a deployment detail: the framework's
 * default lock lasts a day, so a poll killed mid-run would hold it long after every title it was
 * protecting had aged out at D2 § 4.9's 30-minute bound.
 *
 * TEN MINUTES, and the bound is what derives it (BOARD-TASK.md § 12's row). Last good poll at T;
 * the next starts at T+5 and is killed holding the lock; the lock frees at T+5+E and the next tick
 * after that polls. That poll must land before T+30 or every title drops, so E + 5 (the wait for a
 * tick) + 5 < 30, i.e. E < 20. Below that, E must exceed a LIVE poll's own length, or a slow poll
 * gets a second copy beside it: every request is capped at 20 s and a poll is sized at "fifteen
 * requests" (§ 12's timeout row) — 5 minutes. Ten sits between the two with margin either way.
 */
Schedule::command('mezzanine:board-poll')->everyFiveMinutes()->withoutOverlapping(10);

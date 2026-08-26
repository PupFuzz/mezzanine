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

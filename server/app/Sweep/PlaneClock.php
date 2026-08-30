<?php

namespace App\Sweep;

use App\Fold\Clock;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 8.2.4`'s two plane-liveness timestamps: `sweep_last_run_at` and
 * `purge_last_run_at`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * A TIMESTAMP, NOT A NUMBER, AND § 2.3 SAYS WHY IN ONE SENTENCE: "a reader can age a timestamp
 * whose writer has died, and cannot age a number." Both fields exist to be read by something other
 * than the process that writes them — `fleet.sweep` goes `stalled` past 60 s since
 * `sweep_last_run_at` (§ 2.2, § 8.2.4), and a four-day outage of the hourly purge is visible in
 * `purge_last_run_at` ~96 times over (§ 6.7). A counter of passes would freeze at its last value
 * and say nothing about how long ago that was, which is the same defect § 2.3 designs `fold_lag_ms`
 * away from.
 *
 * ABSENCE IS THE NULL. § 8.2.4 declares `sweep_last_run_at` nullable, "`null` before the sweeper's
 * first pass". A missing row IS that state, so the null has exactly one producer and no sentinel
 * value stands in for it — § 6.3's rule that a column meaning zero-for-missing is a read-time
 * fallback to be traced to its write site.
 *
 * ⚠ `plane_state` IS NOT IN § 6.4's DDL. The migration that creates it carries the full reasoning
 * and the PR body reports it as a D2 § 6.4 omission. The design document is not edited here.
 */
final class PlaneClock
{
    /** § 2.1 — the sweeper's own field, read by § 8.2.4's `fleet.sweep`. */
    public const SWEEP = 'sweep_last_run_at';

    /** § 6.7 — the hourly purge's, whose absence is how a dead purge becomes visible. */
    public const PURGE = 'purge_last_run_at';

    /**
     * § 8.2.4: `fleet.sweep` is `stalled` past **60 s** since `sweep_last_run_at`. D2's number,
     * cited not chosen — § 2.2's dead-sweep rule "had a threshold and no field to put it on".
     */
    public const SWEEP_STALLED_AFTER_S = 60;

    /**
     * Record that the named process completed a pass at `$at`.
     *
     * MONOTONE, and the guard is not decoration: two workers of one process (the claim in § 6.5 is
     * `SKIP LOCKED` precisely so a second can be added) would otherwise let a pass that started
     * earlier and finished later drag the stamp BACKWARDS, which is a reader being told the plane
     * is less current than it is — the one direction of error that turns a healthy plane into a
     * `stalled` banner.
     */
    public static function stamp(string $name, string $atSql): void
    {
        $existing = DB::table('plane_state')->where('name', $name)->value('at');

        if ($existing !== null && Clock::toMs($existing) >= Clock::toMs($atSql)) {
            return;
        }

        DB::table('plane_state')->upsert(['name' => $name, 'at' => $atSql], ['name'], ['at']);
    }

    /** `null` before the named process's first pass — § 8.2.4's declared value for exactly that. */
    public static function lastRunAt(string $name): ?string
    {
        return DB::table('plane_state')->where('name', $name)->value('at');
    }

    /**
     * § 8.2.4's `fleet.sweep`: `ok` · `stalled`.
     *
     * ⚠ SEAM. This computes the VALUE; § 8.2 and § 8.3 are the surfaces that carry it, and those
     * are Part B's. It lives here rather than in Part B so that the threshold has one home and the
     * process that writes the timestamp is the one that declares what a stale one means.
     */
    public static function sweepHealth(int $nowMs): string
    {
        $at = self::lastRunAt(self::SWEEP);

        // NO PASS YET IS `stalled`, NOT `ok`. A plane that has never swept has never applied a
        // time-derived transition, so every rendered state on it is wire-derived only — which is
        // exactly the degradation § 2.2 says only a dead sweep can produce ("a dead sweep freezes
        // time-driven ones, and only the second one can leave a dead seat rendering `working`").
        // Reading a null as `ok` would report health from an absence of evidence.
        if ($at === null) {
            return 'stalled';
        }

        return ($nowMs - Clock::toMs($at)) / 1000 > self::SWEEP_STALLED_AFTER_S ? 'stalled' : 'ok';
    }
}

<?php

namespace App\Fold;

use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 7.2`'s server-derived badge set, and § 8.2.1's rendered union.
 *
 * SEVEN ARE DECLARED and this class now raises ALL SEVEN. `fold_lag` was left out by card #7339
 * Part A on the reading that it "belongs to the read side, which is Part B's", and the SWEEPER
 * (card #7712, § 2.1) is what makes that reading unnecessary — it does not make it wrong, and the
 * distinction is the whole of § 2.3:
 *
 *   What § 2.3 forbids is a badge STORED BY THE FOLD, because "a stored `fold_lag_ms` whose only
 *   writer is the fold pass dies with the thing it detects". AT-D2-21's second RED is watching
 *   exactly that. The BASIS is still never stored — `SeatFacts::foldLagMs()` computes it from three
 *   columns two other processes write — so pausing the fold makes the number rise, and the SWEEPER,
 *   which is a different process on a 15 s cadence, is what re-reads it and raises the badge.
 *
 * That is what AT-D2-21's GREEN requires and no read of the wire can supply: "WITHIN 60 s every
 * affected seat badges `fold_lag`", and § 7.2 counts `fold_lag_alarm_entered` "once per lag
 * EPISODE" — an episode is a thing that happens on a clock, and a badge that exists only when
 * somebody happens to fetch a snapshot cannot have one. The fold raises it too, from the same
 * function, so the two writers cannot disagree; the episode counter is incremented in
 * `StateRecompute`, at the single site that compares the new set against the stored one, so it
 * counts once per episode however many writers recompute.
 *
 * These are separate from D1 § 9.3's twelve-member `degraded` array and are NEVER merged into it:
 * that array is what the *reporter* knows about itself, and a `lossy` written by this server would
 * sit beside a `spool_dropped_events` of 0 and contradict the number § 9.3 requires be rendered
 * with it. `epoch_reset` appears in both sets deliberately — two independent observations of one
 * transition, and the two disagreeing is itself a signal.
 */
final class Badges
{
    /** § 7.2's set, in the document's own order. */
    public const SERVER = [
        'seq_gap', 'seq_collision', 'clock_skew', 'epoch_reset', 'reporter_ahead',
        'fold_lag', 'derivation_error',
    ];

    /** D1 § 10.1 / § 12.7 — the gauge badges past ±120 s. */
    public const CLOCK_SKEW_MS = 120_000;

    /**
     * § 2.3's per-seat `fold_lag` threshold: **> 60 s**, D2's number, derived not chosen.
     *
     * "60 s is one heartbeat interval (D1 § 9.1): a seat whose derivation is a whole heartbeat
     * behind has certainly missed at least one input, so the badge cannot fire on a healthy pass."
     * The healthy value is bounded by the fold's own poll plus one pass, ~1 s, so the threshold
     * sits two orders of magnitude above healthy.
     */
    public const FOLD_LAG_MS = 60_000;

    /**
     * Recompute the server badge set for a seat from the facts that raise each one.
     *
     * DERIVED ON EVERY RECOMPUTE RATHER THAN LATCHED, so a badge CLEARS when its condition does.
     * That matters for § 7.3's `badges_since`, which is "the time this server first saw it
     * present" with "a badge that clears dropped from the map" — a latched set would make
     * `badges_since` answer a question about a condition that ended.
     *
     * @param  int  $nowMs  the server clock, passed rather than read, so that the one caller that
     *                      recomputes on a clock (the sweeper) and the one that recomputes on an
     *                      event agree on the instant `fold_lag` is judged against
     * @return list<string>
     */
    public static function serverFor(int $seatRef, object $state, int $nowMs): array
    {
        $counters = DB::table('seat_counters')
            ->where('seat_ref', $seatRef)
            ->whereIn('name', [
                'seq_gap', 'seq_collision', 'seq_epoch_change',
                'ignored_unknown_kinds', 'ignored_unknown_fields', 'coerced_enum_values',
            ])
            ->pluck('value', 'name');

        $badges = [];

        if (($counters['seq_gap'] ?? 0) > 0) {
            $badges[] = 'seq_gap';
        }

        if (($counters['seq_collision'] ?? 0) > 0) {
            $badges[] = 'seq_collision';
        }

        // The gauge the INGEST writes per batch (§ 7.1: "`batches` column, latest into
        // `seat_state`"), read here rather than recomputed — one fact, one home.
        if ($state->clock_skew_ms !== null && abs((int) $state->clock_skew_ms) > self::CLOCK_SKEW_MS) {
            $badges[] = 'clock_skew';
        }

        if (($counters['seq_epoch_change'] ?? 0) > 0) {
            $badges[] = 'epoch_reset';
        }

        // § 7.1 gives all three of these counters the same badge: this ingest is behind its fleet.
        if (($counters['ignored_unknown_kinds'] ?? 0) > 0
            || ($counters['ignored_unknown_fields'] ?? 0) > 0
            || ($counters['coerced_enum_values'] ?? 0) > 0) {
            $badges[] = 'reporter_ahead';
        }

        if ((int) $state->fold_errors > 0) {
            $badges[] = 'derivation_error';
        }

        // § 2.3's `fold_lag` badge. COMPUTED, never stored — see the class docblock and
        // `SeatFacts::foldLagMs()`. STRICTLY GREATER, because § 2.3 states the threshold as
        // "> 60 s" and a seat sitting exactly on a boundary is not past it.
        if (SeatFacts::foldLagMs($state, $nowMs) > self::FOLD_LAG_MS) {
            $badges[] = 'fold_lag';
        }

        // Ordered as § 7.2 lists them, so the array is a set with a stable rendering and two equal
        // sets can never compare unequal in the version-bearing fingerprint.
        return array_values(array_intersect(self::SERVER, $badges));
    }

    /**
     * § 8.2.1's rendered `badges`: D1's twelve first, in § 9.3's order, then this document's, in
     * § 7.2's — bounded at 18, the size of the union, `epoch_reset` being in both.
     *
     * @return list<string>
     */
    public static function render(object $state): array
    {
        $reporter = json_decode((string) ($state->reporter_degraded ?? 'null'), true) ?: [];
        $server = json_decode((string) ($state->server_badges ?? 'null'), true) ?: [];

        return array_values(array_unique([...$reporter, ...$server]));
    }

    /**
     * § 7.3's `badges_since` — the minimum of `badge_first_seen`'s values, null when there are
     * none. PER BADGE, not one timestamp for D1's whole twelve-member array: one timestamp cannot
     * answer "when did THIS badge appear", which is the only question this field is asked.
     */
    public static function since(object $state): ?string
    {
        $map = json_decode((string) ($state->badge_first_seen ?? 'null'), true) ?: [];

        return $map === [] ? null : min($map);
    }

    /**
     * Maintain § 6.4's `badge_first_seen` map: a badge currently present keeps the time this
     * server first saw it; a badge that clears is DROPPED from the map.
     *
     * @param  list<string>  $current
     * @return array<string, string>
     */
    public static function firstSeen(?string $storedJson, array $current, string $nowSql): array
    {
        $previous = json_decode((string) ($storedJson ?? 'null'), true) ?: [];
        $map = [];

        foreach ($current as $badge) {
            $map[$badge] = $previous[$badge] ?? $nowSql;
        }

        return $map;
    }
}

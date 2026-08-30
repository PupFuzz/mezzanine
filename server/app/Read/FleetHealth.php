<?php

namespace App\Read;

use App\Fold\Clock;
use App\Fold\SeatFacts;
use App\Sweep\PlaneClock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 8.2.4`'s fleet-health object, in the one place § 8.2.4 asks for
 * it to be: "Carried by THREE SURFACES — `GET /api/fleet/health`, the `fleet` member of every
 * snapshot, and the `fleet` member of every `feed.heartbeat` and `fleet.health` message — and
 * THEREFORE STATED ONCE HERE".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE EIGHT HEALTH FIELDS ARE THE SAME ON ALL THREE, BYTE FOR BYTE; `counters` IS THE NINTH AND
 * IT IS `GET /api/fleet/health`'s ALONE.
 *
 * § 8.2.4 states that asymmetry "as part of the contract rather than left to be discovered,
 * because a consumer that assumed the three were identical would read a missing `counters` as a
 * zeroed one". `withCounters` is therefore a parameter of this builder and not a decision any of
 * the three call sites makes for itself.
 *
 * ⛔ ALL NINE COUNTERS OR NONE. "Whenever the object is present ALL NINE MEMBERS ARE, each at `0`
 * before its first increment: a per-member omission is FORBIDDEN, because an omitted counter and
 * a zero counter are the same wire shape to a consumer and only one of them is true." So the
 * member list below is a constant, not the result of a `SELECT … FROM global_counters` whose
 * rows happen to exist — the counter table starts empty, and a query-shaped implementation would
 * ship `{}` on a healthy new install and be indistinguishable from a broken one.
 *
 * ⛔ AND `null`, NEVER `0`, WHEN THE STORE IS DOWN. "It is `null` — and ONLY — when `db` is
 * `down` … reporting `0` there would be `docs/KANBAN.md § G-1`'s clean zero on the very surface
 * § 2.2 built its read posture to keep honest. `null` says *we could not read these*; `0` would
 * say *nothing has happened*."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ONE POPULATION, NAMED ONCE (§ 8.2.4's `max_fold_lag_ms` row).
 *
 * `seats_total`, `seats_live` and `max_fold_lag_ms` are all computed over EVERY SEAT NOT RETIRED
 * MORE THAN 14 DAYS AGO — not only the live ones. § 8.2.4 argues the case on `max_fold_lag_ms`
 * and it is why `population()` below exists as one query builder rather than three: "two fields
 * of one object reading two populations can disagree: a `stale` seat 117 s behind would set
 * `fleet.fold` to `lagging` while `max_fold_lag_ms` read `0`".
 *
 * ⚠ REPORTED, NOT PATCHED — WHAT § 8.2.4 DOES NOT SAY. Five of the nine members are declared
 * non-null (`fold`, `sweep`, `max_fold_lag_ms`, `seats_total`, `seats_live`) and all five are
 * read from the store, yet § 8.2.4 also declares `db: "down"` reachable on this very object with
 * `counters: null`. There is no honest value for the five in that posture. This class does not
 * invent one: `down()` below returns the object with only the members that ARE knowable, on a
 * `503`, so no consumer can read an invented `ok` or a clean `0`. Card #7827's PR body carries
 * the gap.
 */
final class FleetHealth
{
    /** § 2.3: `fleet.fold = "lagging"` at any seat past 60 s, `"stalled"` past 300 s. */
    public const FOLD_LAGGING_MS = 60_000;

    public const FOLD_STALLED_MS = 300_000;

    /**
     * § 8.2.4's nine fleet-scoped counters, in the order § 7.1 then § 7.2 declare them.
     *
     * The list is a CONSTANT because § 8.2.4 forbids a per-member omission, and the only
     * implementation that cannot omit one is the one that does not read the member list from the
     * data. `tools/design/verify-fleet-state.py` reds when § 7's `Exposed` column names fleet
     * health and § 8.2.4 does not list the counter; this constant is the code-side end of that
     * same pairing.
     */
    public const COUNTERS = [
        // § 7.1 — D1's server-side counters whose exposure surface is fleet health.
        'unattributed_refusals',
        'auth_failed_by_ip',
        'revoked_token_presented',
        // § 7.2 — this plane's own.
        'feed_resync_required',
        'feed_gap_detected',
        'snapshot_served',
        'snapshot_denied',
        'token_wrong_surface',
        'purge_backlog_rows',
    ];

    /**
     * The eight health fields, plus `counters` when the caller is `GET /api/fleet/health`.
     *
     * @return array<string, mixed>
     */
    public static function build(int $nowMs, bool $withCounters = false): array
    {
        $maxLagMs = 0;
        $total = 0;
        $live = 0;

        foreach (self::population() as $state) {
            $total++;

            if ($state->link_state === 'live') {
                $live++;
            }

            $maxLagMs = max($maxLagMs, SeatFacts::foldLagMs($state, $nowMs));
        }

        $health = [
            'db' => 'ok',
            'fold' => self::foldHealth($maxLagMs),
            'sweep' => PlaneClock::sweepHealth($nowMs),
            'sweep_last_run_at' => Clock::wire(PlaneClock::lastRunAt(PlaneClock::SWEEP)),
            'ingest_last_receipt_at' => Clock::wire(DB::table('seat_state')->max('last_receipt_at')),
            'max_fold_lag_ms' => $maxLagMs,
            'seats_total' => $total,
            'seats_live' => $live,
        ];

        return $withCounters ? $health + ['counters' => self::counters()] : $health;
    }

    /**
     * The object when the store could not be read.
     *
     * `db: "down"` is § 8.2.4's "only value that can accompany a response with no seat data", and
     * `counters: null` is its own explicit `db`-down case. The five store-derived members are
     * ABSENT rather than defaulted — see the class docblock: a `0` or an `ok` here is the clean
     * zero § 2.2's whole read posture exists to prevent, and the response carrying this object is
     * a `503`, so nothing can mistake it for an answer.
     *
     * @return array<string, mixed>
     */
    public static function down(bool $withCounters = false): array
    {
        // ⛔ `$withCounters` HERE FOR THE SAME REASON IT EXISTS ON `build()`, and getting it wrong
        // was a real defect in this card's first draft: § 8.2.4 makes `counters` `GET
        // /api/fleet/health`'s ALONE — "the snapshot and the feed NEVER" carry it — and it makes
        // `null` its db-down value. Those two rules compose: on the ENDPOINT a db-down object
        // carries `counters: null`; on the FEED it carries no `counters` member at all. A `null`
        // on the feed would be a member § 8.2.4 says never rides that surface, published on the
        // one path a client has no other way to interpret.
        return ['db' => 'down'] + ($withCounters ? ['counters' => null] : []);
    }

    /** § 2.3's two thresholds, over the population named once above. */
    public static function foldHealth(int $maxLagMs): string
    {
        if ($maxLagMs > self::FOLD_STALLED_MS) {
            return 'stalled';
        }

        return $maxLagMs > self::FOLD_LAGGING_MS ? 'lagging' : 'ok';
    }

    /**
     * § 8.2.4 / § 4.10: every seat NOT retired more than 14 days ago — the one population.
     *
     * The predicate itself is `App\Read\RetirementFilter`'s, not this class's — see that class
     * for why one home rather than a `where()` per read site.
     *
     * @return Collection<int, object>
     */
    public static function population(): Collection
    {
        return RetirementFilter::renderable(
            DB::table('seat_state')->join('seats', 'seats.id', '=', 'seat_state.seat_ref')
        )->get(['seat_state.*']);
    }

    /** @return array<string, int> */
    private static function counters(): array
    {
        $stored = DB::table('global_counters')
            ->whereIn('name', self::COUNTERS)
            ->pluck('value', 'name');

        $out = [];

        foreach (self::COUNTERS as $name) {
            $out[$name] = (int) ($stored[$name] ?? 0);
        }

        return $out;
    }
}

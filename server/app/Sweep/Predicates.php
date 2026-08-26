<?php

namespace App\Sweep;

use App\Fold\Clock;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 5` — every server-side predicate reports BOTH branch counts, and
 * the sweeper alarms when one goes constant against its stated criterion.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS AT ALL. A peer install ran **30 days dark** because a predicate stopped
 * discriminating and nothing noticed: a seat-detection guard pinned to "always suppress", two
 * consumers silent, and "wrong" and "working" indistinguishable from outside for a month
 * (D1 § 3.4, measured in this fleet 2026-08-23). D1 answers it on the reporter; § 5 answers it on
 * the server, because the same failure is available here — "a staleness predicate that can only say
 * `live`, an idle predicate that can only say `no`".
 *
 * § 5's three binding rules, and where each one lands in this file:
 *   1. NO PREDICATE GATES ON AN UNDOCUMENTED ENVIRONMENT MARKER. Every input below is a stored
 *      column or a constant in `CRITERIA`. A predicate that would need `getenv()` is a defect.
 *   2. Both branch counts, on every evaluation — `record()`, which never takes a "skip" path.
 *   3. Every predicate names the control that proves it can produce both answers, and that control
 *      is a TEST, not a paragraph (AT-D2-13). The controls are § 5's table; the tests are the
 *      suite's.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THE WINDOWED CRITERIA ARE APPROXIMATED, AND THE APPROXIMATION IS REPORTED RATHER THAN HIDDEN.
 *
 * § 5 states four of its seven criteria over a ROLLING WINDOW — "constant-`false` across ≥ 5,760
 * evaluations in a rolling 7 days", "0 % or 100 % across ≥ 200 evaluations in a rolling 24 h",
 * "≥ 5 % server-closed across ≥ 1,000 in 24 h". § 6.4's `seat_predicates` carries **cumulative**
 * counts and two last-seen timestamps and nothing else, so a windowed count is not derivable from
 * it: the schema cannot express the criteria the same document states. That is a D2 internal
 * inconsistency and it is filed in the PR body, NOT resolved by adding columns to a table § 6.4
 * declares in full ("Names are final; a builder may reorder columns and add nothing").
 *
 * What ships instead, per kind, with the direction of its error named:
 *
 *   CONSECUTIVE (`ingest_receiving`, `fold_current`) — EXACT. "Constant for 2 consecutive passes"
 *   is a property of the transition, so `record()` reads the prior branch off the row it is about
 *   to overwrite and decides there. No window is involved and nothing is approximated.
 *
 *   CONSTANT (`seat_live`, `activity_recent`) — the CONSTANCY half is exact: "no `true` in the
 *   window" is exactly "`last_true_at` is null or older than the window", and both columns are
 *   § 6.4's. The EVIDENCE half — "across ≥ N evaluations" — is bounded from two sides, and firing
 *   requires both: the cumulative count of the constant branch must be ≥ N (NECESSARY for N of
 *   them to have happened in any window, so this can never over-fire), and the constancy run must
 *   have lasted at least N × the predicate's own cadence (SUFFICIENT when the sweeper is running at
 *   cadence, which is the case § 5's arithmetic assumes when it converts 5,760 evaluations into
 *   "a seat-day of passes"). The conjunction under-fires on a sweeper that has been intermittent —
 *   it waits for more evidence — and never over-fires. Under-firing is the safe direction for an
 *   alarm whose whole job is to be trusted when it does fire.
 *
 *   RATIO (`turn_clean`, `call_closed_by_wire`, `attention_resolved_by_wire`) — the share is
 *   CUMULATIVE rather than windowed, with the floor (`n`) applied to the cumulative total and a
 *   liveness bound requiring the predicate to have been evaluated at all inside its window. A
 *   cumulative share is slower to move than a windowed one, so this under-fires on a defect that
 *   started recently against a long clean history, and it does not over-fire. § 5 already says
 *   ALL of these numbers are provisional — "the implementer records per-predicate evaluation
 *   counts through the first week of live running and the operator re-picks every number from that
 *   data" — so the windowing is on the same review path as the thresholds it applies to.
 */
final class Predicates
{
    /** The fleet-wide sentinel of § 6.4: NOT a real row in `seats`, which is why there is no FK. */
    public const FLEET = 0;

    /**
     * § 5's seven, and the criterion each one alarms on. Every number is § 5's own.
     *
     * `cadence_s` is the predicate's own evaluation rate where it HAS one — § 2.1's 15 s sweep
     * pass. The three event-driven predicates have no cadence, which is why their criteria are
     * ratios over a floor rather than constancy over a count of passes: § 5's rule is that "a
     * threshold above a predicate's own rate is an alarm that can never fire", so each criterion
     * is stated in the units its own subject produces.
     */
    private const CRITERIA = [
        // Constant-TRUE is deliberately NOT a criterion here: "a fleet in which no seat is ever
        // stale for a week is the good outcome, and an alarm that fires on the healthy case is
        // worse than no alarm, because it is the one that gets trained away."
        'seat_live' => [
            'kind' => 'constant', 'direction' => 'false', 'n' => 5760,
            'cadence_s' => Sweep::CADENCE_S, 'window_s' => 7 * 86400,
        ],

        // BOTH directions, and unlike `seat_live` both are right here. Constant-`true` means a seat
        // has done something in the activity set every 15 minutes for a week without a single quiet
        // quarter-hour, which no real desk does AND A RECEIPT-FED ACTIVITY COLUMN DOES EXACTLY.
        'activity_recent' => [
            'kind' => 'constant', 'direction' => 'either', 'n' => 5760,
            'cadence_s' => Sweep::CADENCE_S, 'window_s' => 7 * 86400,
        ],

        // 0 % or 100 %. The 100 % end is kept against `seat_live`'s rule and § 5 records the
        // asymmetry rather than leaving it as an inconsistency: 200 consecutive clean turns is a
        // plausible healthy day, so this can cry wolf — and what it would otherwise MISS is the
        // false-idle defect itself, D1's headline failure arriving through a derivation that has
        // stopped seeing aborts.
        'turn_clean' => ['kind' => 'ratio', 'n' => 200, 'window_s' => 86400],

        // NOT constancy — a SHARE, and the alarm direction is "server closes should be rare".
        'call_closed_by_wire' => ['kind' => 'false_share', 'share' => 0.05, 'n' => 1000, 'window_s' => 86400],

        // ANY server-ceiling resolution in 24 h is surfaced; constant-server over ≥ 10 alarms.
        'attention_resolved_by_wire' => ['kind' => 'any_false', 'n' => 10, 'window_s' => 86400],

        // The predicate that separates "every seat died" from "our pipe is broken" — without it a
        // fleet-wide ingest outage renders as 40 independently-stale desks.
        'ingest_receiving' => ['kind' => 'consecutive', 'direction' => 'false', 'n' => 2],

        // Reachable ONLY because the sweeper and the fold are different processes and the lag's
        // basis is a timestamp TWO processes write (§ 2.3). A stored lag the fold wrote would
        // freeze with it and this predicate could never flip.
        'fold_current' => ['kind' => 'consecutive', 'direction' => 'false', 'n' => 2],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::CRITERIA);
    }

    /**
     * Record ONE evaluation of one predicate. § 5 rule 2: both branch counts, on every evaluation.
     *
     * There is deliberately no early return and no "unknown" branch. A predicate that declines to
     * answer records nothing, and a row that stops moving is indistinguishable from a predicate
     * nobody evaluates — which is the 30-day-dark shape in miniature.
     */
    /**
     * @param  int  $times  how many evaluations of this branch to record in one write. Always 1 for
     *                      a per-pass predicate; more only where ONE physical event is N evaluations
     *                      — a `session.end` closing N orphaned calls is N closes of
     *                      `call_closed_by_wire`, and recording them one at a time would put 2N
     *                      statements on the fold's hot path for a value that is one addition.
     *
     * ⚠ THIS IS A READ-MODIFY-WRITE AND `Counters` DELIBERATELY IS NOT, so the difference is
     * stated. `Counters::upsert` writes `value = value + ?` in one statement precisely so that
     * concurrent ingest requests for one seat cannot lose an increment. This method cannot: the
     * CONSECUTIVE criterion needs the branch the PREVIOUS evaluation took, which is only readable
     * from the row this write overwrites. What makes that safe is the write population rather than
     * a lock — every per-seat row is written inside its writer's own per-seat transaction (the
     * fold's § 6.5 claim is `SKIP LOCKED` plus a cursor guard, so two workers never hold one seat),
     * and the fleet-wide row has exactly one writer, the sweeper.
     */
    public static function record(int $seatRef, string $name, bool $branch, string $nowSql, int $times = 1): void
    {
        if ($times < 1) {
            return;
        }

        $prior = DB::table('seat_predicates')
            ->where('seat_ref', $seatRef)->where('name', $name)->first();

        $trueCount = (int) ($prior->true_count ?? 0) + ($branch ? $times : 0);
        $falseCount = (int) ($prior->false_count ?? 0) + ($branch ? 0 : $times);

        $row = [
            'seat_ref' => $seatRef,
            'name' => $name,
            'true_count' => $trueCount,
            'false_count' => $falseCount,
            'last_true_at' => $branch ? $nowSql : ($prior->last_true_at ?? null),
            'last_false_at' => $branch ? ($prior->last_false_at ?? null) : $nowSql,
            'alarm_since' => $prior->alarm_since ?? null,
        ];

        // THE CONSECUTIVE CRITERION IS DECIDED HERE AND NOWHERE ELSE, because its input is the
        // TRANSITION and not the row: "constant-`false` for 2 consecutive passes". The prior
        // branch is readable only from the row this write is about to overwrite, so an alarm pass
        // running later over the stored counts could not reconstruct it. Every caller of this
        // method for a consecutive-kind predicate is the sweeper itself (§ 2.1's per-pass jobs), so
        // the criterion still belongs to job 7 — it is evaluated at the one moment its inputs exist.
        $criterion = self::CRITERIA[$name] ?? null;

        if ($criterion !== null && $criterion['kind'] === 'consecutive') {
            // A batched write collapses N evaluations into one row state, which is exactly what a
            // criterion about CONSECUTIVE evaluations cannot read. No consecutive-kind predicate has
            // a batching caller (both are per-pass, per-seat), so this is an unreachable
            // combination — and it raises rather than silently answering about the wrong N.
            if ($times !== 1) {
                throw new \LogicException($name.' is a consecutive-criterion predicate and cannot be batched');
            }

            $priorBranch = self::priorBranch($prior);
            $constant = $priorBranch !== null
                && $priorBranch === $branch
                && $branch === ($criterion['direction'] === 'true');

            $row['alarm_since'] = $constant ? ($prior->alarm_since ?? $nowSql) : null;
        }

        DB::table('seat_predicates')->upsert(
            [$row],
            ['seat_ref', 'name'],
            ['true_count', 'false_count', 'last_true_at', 'last_false_at', 'alarm_since'],
        );
    }

    /**
     * The branch the PREVIOUS evaluation took, read off the two last-seen timestamps.
     *
     * Null when the row has never been evaluated (no prior branch to be consecutive with) — which
     * is why a brand-new predicate cannot alarm on its very first `false`. § 5's criterion is
     * "2 consecutive passes", and one pass is not two.
     */
    private static function priorBranch(?object $prior): ?bool
    {
        if ($prior === null) {
            return null;
        }

        $t = $prior->last_true_at === null ? null : Clock::toMs($prior->last_true_at);
        $f = $prior->last_false_at === null ? null : Clock::toMs($prior->last_false_at);

        if ($t === null && $f === null) {
            return null;
        }

        if ($t === null) {
            return false;
        }

        if ($f === null) {
            return true;
        }

        // Equal timestamps mean two evaluations landed in the same millisecond, which on a 15 s
        // cadence is only reachable in a test that drives passes without moving the clock. The
        // TRUE branch wins the tie deliberately: it is the branch whose presence CLEARS a
        // constant-false alarm, so a tie resolves toward not alarming.
        return $t >= $f;
    }

    /**
     * `docs/design/FLEET-STATE.md § 2.1`'s SEVENTH sweep job: the predicate-constant alarms.
     *
     * Runs over every row of `seat_predicates`, not over a list of names, so a predicate recorded
     * by a writer this class does not know about is still alarmed on. A row whose name has no
     * criterion is left alone rather than defaulted — a criterion nobody chose is a threshold
     * nobody can defend.
     *
     * @return list<array{seat_ref: int, name: string}> the rows alarming after this pass
     */
    public static function alarm(int $nowMs, string $nowSql): array
    {
        $alarming = [];

        foreach (DB::table('seat_predicates')->orderBy('seat_ref')->orderBy('name')->get() as $row) {
            $criterion = self::CRITERIA[$row->name] ?? null;

            if ($criterion === null) {
                continue;
            }

            // The consecutive kind was already decided at `record()` time (see there). Re-deciding
            // it here off the stored counts would silently answer a different question, so this
            // pass reads its verdict rather than recomputing one.
            $fires = $criterion['kind'] === 'consecutive'
                ? $row->alarm_since !== null
                : self::fires($criterion, $row, $nowMs);

            if ($fires && $row->alarm_since === null) {
                DB::table('seat_predicates')
                    ->where('seat_ref', $row->seat_ref)->where('name', $row->name)
                    ->update(['alarm_since' => $nowSql]);
            }

            if (! $fires && $row->alarm_since !== null) {
                DB::table('seat_predicates')
                    ->where('seat_ref', $row->seat_ref)->where('name', $row->name)
                    ->update(['alarm_since' => null]);
            }

            if ($fires) {
                $alarming[] = ['seat_ref' => (int) $row->seat_ref, 'name' => (string) $row->name];
            }
        }

        return $alarming;
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private static function fires(array $c, object $row, int $nowMs): bool
    {
        $t = (int) $row->true_count;
        $f = (int) $row->false_count;
        $total = $t + $f;

        return match ($c['kind']) {
            'constant' => self::constant($c, $row, $nowMs),

            // 0 % OR 100 %, over a floor. Stated as the two ends rather than as "constant" because
            // § 5's own control for this predicate drives BOTH — AT-D2-2's `/clear` fixture drives
            // `false`, AT-D2-1's ordinary turn drives `true` — and a run of one is exactly what a
            // wrongly-scoped reap looked like in D1's own review.
            'ratio' => $total >= $c['n']
                && ($t === 0 || $f === 0)
                && self::evaluatedWithin($c, $row, $nowMs),

            // NOT constancy: server closes should be RARE, so the alarm is a share crossing.
            'false_share' => $total >= $c['n']
                && $f / max(1, $total) >= $c['share']
                && self::evaluatedWithin($c, $row, $nowMs),

            // "ANY server-ceiling resolution in 24 h is surfaced; constant-server over ≥ 10
            // alarms." Two conditions on one row: the recent-any, and the constant-over-floor.
            'any_false' => ($row->last_false_at !== null
                    && $nowMs - Clock::toMs($row->last_false_at) <= $c['window_s'] * 1000)
                || ($t === 0 && $f >= $c['n'] && self::evaluatedWithin($c, $row, $nowMs)),

            default => throw new \LogicException('no alarm rule for predicate kind '.$c['kind']),
        };
    }

    /**
     * The CONSTANT kind, with the two-sided evidence bound the class docblock explains.
     *
     * @param  array<string, mixed>  $c
     */
    private static function constant(array $c, object $row, int $nowMs): bool
    {
        foreach ($c['direction'] === 'either' ? [true, false] : [$c['direction'] === 'true'] as $branch) {
            $oppositeAt = $branch ? $row->last_false_at : $row->last_true_at;
            $count = (int) ($branch ? $row->true_count : $row->false_count);

            // NECESSARY: N evaluations of one branch cannot have happened in any window unless N
            // of them happened at all. This term alone can never make the alarm fire early.
            if ($count < $c['n']) {
                continue;
            }

            // SUFFICIENT at cadence: the run of the constant branch, measured from the last
            // opposite one (or from the first evaluation ever, when the opposite has never been
            // taken — in which case the cumulative count above IS the windowed one, exactly).
            $runMs = $oppositeAt === null ? PHP_INT_MAX : $nowMs - Clock::toMs($oppositeAt);

            if ($runMs < $c['n'] * $c['cadence_s'] * 1000) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * The liveness bound on a ratio criterion: a predicate that stopped being evaluated a month ago
     * must not keep alarming on the shape of a history nobody is adding to. A stuck alarm is the
     * one that gets trained away, which is the failure § 5's whole argument is about.
     *
     * @param  array<string, mixed>  $c
     */
    private static function evaluatedWithin(array $c, object $row, int $nowMs): bool
    {
        $last = max(
            $row->last_true_at === null ? 0 : Clock::toMs($row->last_true_at),
            $row->last_false_at === null ? 0 : Clock::toMs($row->last_false_at),
        );

        return $last > 0 && $nowMs - $last <= $c['window_s'] * 1000;
    }
}

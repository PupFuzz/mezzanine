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
 * ⛔ FOUR OF § 5's SEVEN CRITERIA CANNOT BE EVALUATED FROM § 6.4's TABLE, AND THE ALARM SAYS SO
 * RATHER THAN GUESSING. THAT IS THE WHOLE OF THIS SECTION.
 *
 * § 5 states four of its criteria over a ROLLING WINDOW — "constant-`false` across ≥ 5,760
 * evaluations in a rolling 7 days", "constant across ≥ 5,760 evaluations in a rolling 7 days",
 * "0 % or 100 % across ≥ 200 evaluations in a rolling 24 h", "≥ 5 % server-closed across ≥ 1,000
 * in 24 h". Every one of those is a COUNT OF EVALUATIONS INSIDE A WINDOW. § 6.4's
 * `seat_predicates` carries cumulative `true_count` / `false_count` and two last-seen timestamps
 * and nothing else, from which no windowed count is derivable. The schema cannot express the
 * criteria the same document states; that is a D2 internal inconsistency, it is REPORTED in the PR
 * body, and it is NOT resolved by adding columns to a table § 6.4 declares in full ("Names are
 * final; a builder may reorder columns and add nothing").
 *
 * ⚠ AN EARLIER REVISION OF THIS FILE APPROXIMATED THEM AND CLAIMED THE ERROR RAN IN THE SAFE
 * DIRECTION. THE CLAIM WAS FALSE IN BOTH DIRECTIONS AND THE APPROXIMATION IS GONE. It is recorded
 * here rather than deleted, because the argument is exactly the one a later implementer will
 * re-invent:
 *
 *   The CONSTANT kinds conjoined a cumulative count with a WALL-CLOCK run length — "the constancy
 *   run has lasted at least N × cadence" — and called the pair a windowed count. That proxy holds
 *   only while the sweeper is evaluating AT cadence, and § 2.2 devotes a whole row to a dead sweep
 *   worker, so the one condition that breaks it is a condition the design explicitly expects.
 *   After a sweeper outage the wall clock has advanced and the evaluation count has not: a seat
 *   with an ordinary historical `false_count` FIRES ON ITS FIRST EVALUATION against a criterion
 *   demanding 5,760 in a rolling seven days.
 *
 *   The RATIO and FALSE_SHARE kinds computed the share from CUMULATIVE counts and bounded it only
 *   with "some evaluation happened inside the window". A months-old incident therefore stayed
 *   latched FOR EVER and no volume of later clean evaluations could clear it — precisely the stuck
 *   alarm § 5 is written against.
 *
 * WHY REFUSING IS THE ANSWER AND TUNING IS NOT. § 5's own recorded trade is that "an alarm that
 * fires on the healthy case is worse than no alarm, because it is the one that gets trained away".
 * An alarm biased toward over-firing does not degrade the feature, it INVERTS it. A predicate that
 * cannot be evaluated must SAY SO — which is the rule this repo applies to every other check, and
 * why `SeatFacts::foldLagMs()` raises instead of `COALESCE`-ing a missing basis to zero.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT SHIPS, PER KIND. Three outcomes, not two: `FIRES`, `CLEAR`, `CANNOT_EVALUATE`.
 *
 *   CONSECUTIVE (`ingest_receiving`, `fold_current`) — EXACT, so it answers. "Constant for 2
 *   consecutive passes" is a property of the TRANSITION, not of a window, so `record()` reads the
 *   prior branch off the row it is about to overwrite and decides there. No count, no clock.
 *
 *   ANY_FALSE (`attention_resolved_by_wire`) — EXACT, so it answers, and it is the one windowed
 *   phrase in § 5 that needs no COUNT. Its criterion is "**any** server-ceiling resolution in 24 h
 *   is surfaced; constant-server over ≥ 10 alarms". The first clause is an EXISTENCE test over the
 *   window and `last_false_at` is exactly the timestamp it asks about. The second clause is proved
 *   SUBSUMED by the first at the `outcome()` arm rather than coded as a disjunct that could never
 *   decide a row — see there.
 *
 *   CONSTANT (`seat_live`, `activity_recent`), RATIO (`turn_clean`), FALSE_SHARE
 *   (`call_closed_by_wire`) — `CANNOT_EVALUATE`, on every row, on every pass. Their evidence term
 *   is a count of evaluations inside a rolling window and the store has no such count. They never
 *   set `alarm_since` and never clear it; the outcome is returned by name so the refusal is a
 *   REPORTED state rather than a silent `false`.
 *
 * ⚠ `CRITERIA`'s numeric terms for those four kinds (`n`, `share`, `window_s`, `cadence_s`) are
 * therefore DECLARED AND NOT APPLIED. They are kept deliberately: they are § 5's own numbers and
 * they are the specification of what a widened `seat_predicates` would have to carry, which is the
 * D2 change filed separately. Deleting them would delete the statement of the gap.
 */
final class Predicates
{
    /** The fleet-wide sentinel of § 6.4: NOT a real row in `seats`, which is why there is no FK. */
    public const FLEET = 0;

    /** The criterion is met on this row: `alarm_since` is set (or left where it already was). */
    public const FIRES = 'fires';

    /** The criterion is NOT met: `alarm_since` is cleared. An alarm that cannot clear gets trained away. */
    public const CLEAR = 'clear';

    /**
     * ⛔ THE THIRD OUTCOME, AND IT IS NOT `false`.
     *
     * The criterion's evidence term is a count of evaluations inside a rolling window and § 6.4's
     * `seat_predicates` carries no windowed count (see the class docblock). Folding this into
     * `CLEAR` would assert "this predicate is healthy" on no evidence, and folding it into `FIRES`
     * is the over-firing this outcome exists to replace. A row that reports this is a row whose
     * criterion NOBODY IS CHECKING, which is a fact an operator has to be able to see — it is the
     * 30-day-dark shape § 5 opens with, in miniature.
     *
     * Writes NOTHING to `alarm_since`: there is no verdict to store, and storing either value
     * would fabricate one.
     */
    public const CANNOT_EVALUATE = 'cannot_evaluate';

    /**
     * § 5's seven, and the criterion each one alarms on. Every number is § 5's own.
     *
     * `cadence_s` is the predicate's own evaluation rate where it HAS one — § 2.1's 15 s sweep
     * pass. The three event-driven predicates have no cadence, which is why their criteria are
     * ratios over a floor rather than constancy over a count of passes: § 5's rule is that "a
     * threshold above a predicate's own rate is an alarm that can never fire", so each criterion
     * is stated in the units its own subject produces.
     *
     * ⚠ THIS TABLE IS § 5's DECLARATION, NOT A LIST OF THINGS THE CODE APPLIES. Only the
     * `consecutive` and `any_false` rows are evaluated; the other four report `CANNOT_EVALUATE`
     * because their evidence term is a count of evaluations inside a rolling window and § 6.4's
     * table carries no such count (class docblock). Their `n` / `share` / `window_s` / `cadence_s`
     * / `direction` are kept for one reason: they are the SPECIFICATION of what a widened
     * `seat_predicates` would have to carry, and that widening is the D2 change filed separately.
     * A reader must not infer from a number's presence here that something reads it.
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
     * ⛔ THIS IS A READ-MODIFY-WRITE AND IT IS NOT SAFE ON MySQL. THE EARLIER JUSTIFICATION HERE
     * WAS FALSE AND IS CORRECTED RATHER THAN SOFTENED — the fix is card #7523's (the store host),
     * because the race is not reachable on the SQLite the suite runs against.
     *
     * `Counters::upsert` writes `value = value + ?` in ONE statement precisely so that concurrent
     * writers for one seat cannot lose an increment. This method cannot do that: the CONSECUTIVE
     * criterion needs the branch the PREVIOUS evaluation took, which is only readable from the row
     * this write is about to overwrite.
     *
     * What this docblock USED TO CLAIM made that safe was the write population — "the fold's § 6.5
     * claim is `SKIP LOCKED` plus a cursor guard, so two workers never hold one seat". THAT LOCK
     * EXCLUDES OTHER *FOLD* WORKERS AND NOTHING ELSE, and two of these predicates are written by
     * two different PROCESSES:
     *
     *   `call_closed_by_wire`        fold `tool.end` (`Projector.php`) · sweeper orphan close and
     *                                quiescence (`Sweep.php`)
     *   `attention_resolved_by_wire` fold `attention.resolved` · sweeper 60-minute ceiling
     *
     * A fold transaction and a sweep transaction can therefore interleave on ONE row of
     * `seat_predicates`: both read the same prior counts and the second write silently discards the
     * first's increment. On MySQL that is an ordinary lost update. It is unreachable on SQLite,
     * whose connection-level write serialization means the suite cannot exercise it — so this is
     * REPORTED, not "tested and fine".
     *
     * ⚠ AND THE SAME TWO WRITERS TAKE THE TWO TABLES IN OPPOSITE ORDERS, which is a deadlock cycle
     * and not merely a lost update. A sweep transaction writes `seat_predicates` (the orphan close)
     * and then `seat_state` (the recompute). A fold transaction applies a WINDOW of events, so an
     * earlier event's recompute writes `seat_state` before a later `tool.end` writes
     * `seat_predicates`. Same seat, opposite order, both under row locks: A-B versus B-A.
     * Carried onto card #7523 with the rest of the MySQL-only exposure.
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
     * ⛔ RETURNS AN OUTCOME FOR EVERY ROW IT VISITED, NOT JUST THE ALARMING ONES, because
     * `CANNOT_EVALUATE` is a report and a return value that only carried `FIRES` could not make it.
     *
     * ⚠ NOTHING CONSUMES THIS RETURN YET, AND THAT IS STATED RATHER THAN PAPERED OVER WITH AN
     * INVENTED CONSUMER. `Sweep::pass()` is the only caller and it discards the value; the stored
     * surface an operator reads today is `seat_predicates.alarm_since`, which by construction can
     * never be non-null for a `CANNOT_EVALUATE` kind. The declared home for a fleet-level report is
     * § 8.2.4's fleet-health object, which is card #7339 PART B's and is not built — inventing a
     * field for it here would be inventing a wire contract another card owns (§ 1.3).
     *
     * @return list<array{seat_ref: int, name: string, outcome: string}>
     */
    public static function alarm(int $nowMs, string $nowSql): array
    {
        $outcomes = [];

        foreach (DB::table('seat_predicates')->orderBy('seat_ref')->orderBy('name')->get() as $row) {
            $criterion = self::CRITERIA[$row->name] ?? null;

            if ($criterion === null) {
                continue;
            }

            $outcome = self::outcome($criterion, $row, $nowMs);

            if ($outcome === self::FIRES && $row->alarm_since === null) {
                DB::table('seat_predicates')
                    ->where('seat_ref', $row->seat_ref)->where('name', $row->name)
                    ->update(['alarm_since' => $nowSql]);
            }

            if ($outcome === self::CLEAR && $row->alarm_since !== null) {
                DB::table('seat_predicates')
                    ->where('seat_ref', $row->seat_ref)->where('name', $row->name)
                    ->update(['alarm_since' => null]);
            }

            // CANNOT_EVALUATE falls through both branches deliberately: it neither sets a verdict
            // nor withdraws one. It cannot strand a latched alarm either, and that is provable
            // rather than hoped — `alarm_since` has exactly two writers, the `consecutive` branch
            // of `record()` and the two updates above, so a row of a CANNOT_EVALUATE kind is
            // created with `alarm_since` NULL and nothing in this class can ever set it.

            $outcomes[] = [
                'seat_ref' => (int) $row->seat_ref,
                'name' => (string) $row->name,
                'outcome' => $outcome,
            ];
        }

        return $outcomes;
    }

    /**
     * ONE ROW, ONE OF THREE ANSWERS. The class docblock argues the split; this is where it lands.
     *
     * @param  array<string, mixed>  $c
     */
    private static function outcome(array $c, object $row, int $nowMs): string
    {
        return match ($c['kind']) {
            // Decided at `record()` time, off the TRANSITION, and merely read here. Re-deciding it
            // from the stored counts would silently answer a different question — the counts cannot
            // reconstruct which branch the previous evaluation took.
            'consecutive' => $row->alarm_since !== null ? self::FIRES : self::CLEAR,

            // ⛔ THE THREE WINDOWED KINDS. Their evidence term is "≥ N evaluations in a rolling
            // window" and `seat_predicates` has no windowed count, so there is no answer to give.
            // This is NOT a `false`: see the class docblock for what the earlier approximation did
            // in each direction, and why refusing beats tuning.
            'constant', 'ratio', 'false_share' => self::CANNOT_EVALUATE,

            // EXACT, so it answers. § 5's criterion is "**any** server-ceiling resolution in 24 h
            // is surfaced; constant-server over ≥ 10 alarms", and what is evaluated here is the
            // FIRST clause alone — an EXISTENCE test over the window, for which `last_false_at` is
            // exactly the timestamp asked about. No count, no proxy.
            //
            // ⛔ THE SECOND CLAUSE IS NOT DROPPED, IT IS SUBSUMED, AND THE SUBSUMPTION IS A PROOF
            // RATHER THAN A JUDGEMENT CALL. "Constant-server" is `true_count === 0`, and
            // `record()` writes `last_true_at` on exactly the evaluations that increment
            // `true_count` — so `true_count === 0` holds if and only if `last_true_at IS NULL`, and
            // the newest evaluation this predicate has ever had is therefore `last_false_at`. Any
            // reading of clause two that keeps it inside § 5's 24 h window is then already clause
            // one, with `false_count ≥ 10` narrowing it further; and a reading that puts clause two
            // over the LIFETIME instead needs a windowed count to be worth anything more, which is
            // gap 3 again. An earlier revision coded clause two as a live disjunct guarded by a
            // liveness bound: that disjunct could never decide a row either way, which is the
            // decoration this repo refuses everywhere else. Reported with the § 5 gaps.
            'any_false' => $row->last_false_at !== null
                && $nowMs - Clock::toMs($row->last_false_at) <= $c['window_s'] * 1000
                    ? self::FIRES : self::CLEAR,

            default => throw new \LogicException('no alarm rule for predicate kind '.$c['kind']),
        };
    }
}

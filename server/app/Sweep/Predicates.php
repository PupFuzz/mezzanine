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
 * ⛔ ALL SEVEN CRITERIA ARE EVALUATED FROM § 6.4's COLUMNS, AND THAT IS A CHANGE (card #7833).
 *
 * An earlier revision of this file could evaluate only three of the seven and returned a named
 * `cannot_evaluate` for the other four, because § 6.4's `seat_predicates` carried CUMULATIVE
 * `true_count` / `false_count` and two last-seen timestamps and nothing else, from which no
 * windowed count is derivable. That refusal was honest and it was INERT: § 5's four headline
 * criteria did not alarm at all. Card #7833 ruled the fix — EXTEND THE STORE, do not restate the
 * criteria — and § 6.4 now carries the two pieces of evidence the criteria actually ask for:
 *
 *   run_length / run_started_at   the CONSTANCY evidence: how many evaluations the current
 *                                 unbroken same-branch run holds, and when it began. The run's
 *                                 BRANCH is NOT stored — it is derived from `last_true_at` /
 *                                 `last_false_at` by `priorBranch()`, which already owned that
 *                                 fact, and a second copy would be free to disagree with it.
 *   window_* / prev_*             the SHARE evidence: a TUMBLING 24 h window of branch counts,
 *                                 and the last COMPLETED one.
 *
 * WHY THOSE TWO SHAPES AND NOT A BUCKET TABLE. A per-evaluation bucket row would put a second
 * row-write on the fold's hot path, written by two processes — a second A-B/B-A lock cycle on top
 * of the one already filed on card #7523 (below). Columns on this row add NO new statement:
 * `record()` is already a `SELECT` plus one whole-row upsert, and every value below is computed in
 * PHP from the row it had already read.
 *
 * ⚠ THE APPROXIMATION THAT CAME BEFORE THE REFUSAL IS RECORDED HERE RATHER THAN DELETED, because
 * the argument is exactly the one a later implementer will re-invent — and both of its failures are
 * regression tests in `PredicateAlarmsTest`:
 *
 *   The CONSTANT kinds conjoined a CUMULATIVE count with a WALL-CLOCK run length and called the
 *   pair a windowed count. It is one only while the sweeper is evaluating AT cadence, and § 2.2
 *   devotes a whole row to a dead sweep worker. After a sweeper outage the wall clock has advanced
 *   and the evaluation count has not: a seat with an ordinary historical `false_count` FIRED ON ITS
 *   FIRST EVALUATION against a criterion demanding 5,760 of them. A stored RUN cannot do that — a
 *   lifetime `false_count` of 5,760 broken by a single `true` is a run of whatever followed it.
 *
 *   The RATIO and FALSE_SHARE kinds computed the share from CUMULATIVE counts and bounded it only
 *   with "some evaluation happened inside the window". A months-old incident therefore stayed
 *   latched FOR EVER. A tumbling window cannot do that either: forty days with no close rolls the
 *   completed window to EMPTY, and an empty window is below the 1,000 floor.
 *
 * WHY OVER-FIRING IS THE DIRECTION THAT MATTERS. § 5's own recorded trade is that "an alarm that
 * fires on the healthy case is worse than no alarm, because it is the one that gets trained away".
 * An alarm biased toward over-firing does not degrade the feature, it INVERTS it. Every rule below
 * is therefore exact or conservative, never optimistic.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT SHIPS, PER KIND. Two outcomes, not three: `FIRES` and `CLEAR`.
 *
 *   RUN (`seat_live`, `activity_recent`, `turn_clean`, `ingest_receiving`, `fold_current`) — ONE
 *   rule at five settings of `(direction, n, window_s)`, and not five rules. § 5 states every one
 *   of them as constancy: "constant-`false` across ≥ 5,760 evaluations in a rolling 7 days",
 *   "constant … in either direction", "0 % or 100 % across ≥ 200 evaluations in a rolling 24 h"
 *   (0 % and 100 % ARE constancy), "constant-`false` for 2 consecutive passes". Constancy across N
 *   evaluations inside a window W is exactly: an unbroken run of ≥ N evaluations that began no more
 *   than W ago. Both terms are now stored, so the criterion is read and not approximated.
 *
 *     ⛔ DECIDED AT `record()` AND LATCHED IN `alarm_since`, NEVER RECOMPUTED COLD. The latch is
 *     load-bearing rather than an optimisation: a run that crossed N *inside* the window and then
 *     kept going OUTGROWS the window — `now − run_started_at` passes W while the run is still
 *     constant — and a cold recomputation would silently withdraw an alarm whose subject had not
 *     changed. Exactly one event withdraws the verdict: this write breaking the run (or turning it
 *     onto a branch the criterion does not alarm on).
 *
 *     ⚠ WHAT THIS RULE IS NOT EXACT ABOUT, stated rather than claimed away: the window term is
 *     measured from the START of the run, so a run that began before W and only reached N
 *     afterwards does not fire, even though its last N evaluations all fall inside W. Reaching
 *     that case needs the timestamp of the (run_length − N + 1)-th evaluation, which is a bucket
 *     table and is what card #7833 ruled against. The error is in the UNDER-firing direction — the
 *     one § 5 says degrades the feature rather than inverting it — and it is unreachable at a
 *     steady evaluation rate, which is the property § 5 requires of every threshold anyway.
 *
 *   SHARE (`call_closed_by_wire`) — NOT constancy. § 5 states it as a share and says so in terms:
 *   "≥ 5 % server-closed across ≥ 1,000 in 24 h … server closes should be rare". Restating it as a
 *   run criterion would not weaken it, it would DELETE it, and it is the one criterion of the seven
 *   that is a health signal rather than a discrimination meta-monitor. Evaluated over the last
 *   COMPLETED 24 h window — exact over a tumbling window.
 *
 *     ⚠ ROLLING → TUMBLING IS AN ACCEPTED COST, NOT AN IMPLEMENTATION DETAIL (card #7833; § 5
 *     records it too). The alarm can only arrive at a window ROLL, so worst-case detection of a
 *     reap outage stretches by up to one window; and a burst that straddles a boundary can leave
 *     both halves under the 1,000 floor and alarm on neither. Both are under-firing.
 *
 *   ANY_FALSE (`attention_resolved_by_wire`) — EXACT and unchanged. Its criterion is "**any**
 *   server-ceiling resolution in 24 h is surfaced; constant-server over ≥ 10 alarms". The first
 *   clause is an EXISTENCE test over the window and `last_false_at` is exactly the timestamp it
 *   asks about. The second clause is proved SUBSUMED by the first at the `outcome()` arm rather
 *   than coded as a disjunct that could never decide a row — see there.
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
     * § 5's seven, and the criterion each one alarms on. Every number is § 5's own.
     *
     * `direction` is the branch the alarm is ABOUT; `either` alarms on constancy whichever way it
     * went. `n` is the evaluation floor. `window_s` bounds the run's age and is `null` where § 5
     * states no window — "2 consecutive passes" is a criterion about the transition and nothing
     * else, and a bound on its age would be a threshold § 5 never chose.
     *
     * `batched` is `false` on the two predicates whose only writer is the sweeper's own per-pass
     * loop. It is NOT an arithmetic constraint — a batch of N same-branch evaluations extends a run
     * by N correctly — it is the assertion that these two have no batching caller, so a `$times`
     * above 1 is a wiring mistake and raises instead of being absorbed.
     */
    private const CRITERIA = [
        // Constant-TRUE is deliberately NOT a criterion here: "a fleet in which no seat is ever
        // stale for a week is the good outcome, and an alarm that fires on the healthy case is
        // worse than no alarm, because it is the one that gets trained away."
        'seat_live' => [
            'kind' => 'run', 'direction' => 'false', 'n' => 5760, 'window_s' => 7 * 86400,
        ],

        // BOTH directions, and unlike `seat_live` both are right here. Constant-`true` means a seat
        // has done something in the activity set every 15 minutes for a week without a single quiet
        // quarter-hour, which no real desk does AND A RECEIPT-FED ACTIVITY COLUMN DOES EXACTLY.
        'activity_recent' => [
            'kind' => 'run', 'direction' => 'either', 'n' => 5760, 'window_s' => 7 * 86400,
        ],

        // 0 % or 100 % — which IS constancy, in either direction, over a 24 h window. The 100 % end
        // is kept against `seat_live`'s rule and § 5 records the asymmetry rather than leaving it
        // as an inconsistency: 200 consecutive clean turns is a plausible healthy day, so this can
        // cry wolf — and what it would otherwise MISS is the false-idle defect itself, D1's
        // headline failure arriving through a derivation that has stopped seeing aborts.
        'turn_clean' => ['kind' => 'run', 'direction' => 'either', 'n' => 200, 'window_s' => 86400],

        // NOT constancy — a SHARE, and the alarm direction is "server closes should be rare".
        //
        // `share_pct` IS AN INTEGER PERCENTAGE because § 5 states the threshold in percent, and
        // `share()` cross-multiplies in integers so the boundary the criterion states — 50 server
        // closes in 1,000 — is met by construction at every total rather than by rounding.
        //
        // ⚠ AND THE OBVIOUS REASON FOR THAT IS NOT THE REAL ONE, so it is corrected here rather
        // than left as a plausible story: `0.05 * 1000` is EXACTLY 50.0 in IEEE-754 — MEASURED,
        // after this comment first claimed otherwise from memory — and `prev_false >= 0.05 * total`
        // diverges from the integer form at NO total in the reachable range (scanned 1,000 to
        // 200,000, zero divergences; `fl(0.05)`'s relative error is below half an ulp, so the
        // product never rounds above `total / 20`). This is therefore a ROBUSTNESS choice with no
        // failing case behind it, and it has no arm in the suite: mutating `share()` to the float
        // form leaves every test green, deliberately. What the boundary arm in
        // `PredicateAlarmsTest` does hold is the THRESHOLD — that 50 in 1,000 fires and 49 does
        // not — which is § 5's claim and not this comment's.
        'call_closed_by_wire' => ['kind' => 'share', 'share_pct' => 5, 'n' => 1000, 'window_s' => 86400],

        // ANY server-ceiling resolution in 24 h is surfaced; constant-server over ≥ 10 alarms.
        'attention_resolved_by_wire' => ['kind' => 'any_false', 'n' => 10, 'window_s' => 86400],

        // The predicate that separates "every seat died" from "our pipe is broken" — without it a
        // fleet-wide ingest outage renders as 40 independently-stale desks.
        'ingest_receiving' => [
            'kind' => 'run', 'direction' => 'false', 'n' => 2, 'window_s' => null, 'batched' => false,
        ],

        // Reachable ONLY because the sweeper and the fold are different processes and the lag's
        // basis is a timestamp TWO processes write (§ 2.3). A stored lag the fold wrote would
        // freeze with it and this predicate could never flip.
        'fold_current' => [
            'kind' => 'run', 'direction' => 'false', 'n' => 2, 'window_s' => null, 'batched' => false,
        ],
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
     * ⛔ THIS IS A READ-MODIFY-WRITE AND IT IS NOT SAFE ON MariaDB. THE EARLIER JUSTIFICATION HERE
     * WAS FALSE AND IS CORRECTED RATHER THAN SOFTENED — the fix is card #7523's (the store host),
     * because the race is not reachable on the SQLite the suite runs against. ⚠ Card #7833's
     * columns were chosen PARTLY so that this posture stays UNTOUCHED: they add state to a row this
     * method already read and already rewrote whole — no new statement, no new writer, no new
     * lock-order edge.
     *
     * `Counters::upsert` writes `value = value + ?` in ONE statement precisely so that concurrent
     * writers for one seat cannot lose an increment. This method cannot do that: the run criterion
     * needs the branch the PREVIOUS evaluation took, which is only readable from the row this write
     * is about to overwrite.
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
     * first's increment. On MariaDB that is an ordinary lost update. It is unreachable on SQLite,
     * whose connection-level write serialization means the suite cannot exercise it — so this is
     * REPORTED, not "tested and fine".
     *
     * ⚠ AND THE SAME TWO WRITERS TAKE THE TWO TABLES IN OPPOSITE ORDERS, which is a deadlock cycle
     * and not merely a lost update. A sweep transaction writes `seat_predicates` (the orphan close)
     * and then `seat_state` (the recompute). A fold transaction applies a WINDOW of events, so an
     * earlier event's recompute writes `seat_state` before a later `tool.end` writes
     * `seat_predicates`. Same seat, opposite order, both under row locks: A-B versus B-A.
     * Carried onto card #7523 with the rest of the MariaDB-only exposure.
     */
    public static function record(int $seatRef, string $name, bool $branch, string $nowSql, int $times = 1): void
    {
        if ($times < 1) {
            return;
        }

        $criterion = self::CRITERIA[$name] ?? null;

        // A batched write collapses N evaluations into one row state at one instant. Neither of the
        // two predicates that declare `batched => false` has a batching caller — both are per-pass,
        // one per seat and one fleet-wide — so this combination is a wiring mistake, and it raises
        // rather than silently answering about a run the caller never actually evaluated.
        if ($criterion !== null && ($criterion['batched'] ?? true) === false && $times !== 1) {
            throw new \LogicException($name.' has no batching caller and cannot be batched');
        }

        $nowMs = Clock::toMs($nowSql);

        $prior = DB::table('seat_predicates')
            ->where('seat_ref', $seatRef)->where('name', $name)->first();

        // ── THE RUN, maintained on every row and read by the `run` kind ───────────────────────
        //
        // ⚠ INVARIANT: `run_length` and `run_started_at` are written together on EVERY write of
        // this row, so a row that has a prior branch has both. That is why the continuation branch
        // reads `run_started_at` directly instead of coalescing a value it cannot be missing.
        $priorBranch = self::priorBranch($prior);
        $runContinues = $priorBranch !== null && $priorBranch === $branch;
        $runLength = ($runContinues ? (int) $prior->run_length : 0) + $times;
        $runStartedAt = $runContinues ? (string) $prior->run_started_at : $nowSql;

        $row = [
            'seat_ref' => $seatRef,
            'name' => $name,
            'true_count' => (int) ($prior->true_count ?? 0) + ($branch ? $times : 0),
            'false_count' => (int) ($prior->false_count ?? 0) + ($branch ? 0 : $times),
            'last_true_at' => $branch ? $nowSql : ($prior->last_true_at ?? null),
            'last_false_at' => $branch ? ($prior->last_false_at ?? null) : $nowSql,
            'alarm_since' => $prior->alarm_since ?? null,
            'run_length' => $runLength,
            'run_started_at' => $runStartedAt,
            // Carried unchanged for every kind but `share`, the only criterion whose evidence is a
            // windowed COUNT. Maintaining a tumbling window for the other six would be stored state
            // nothing reads.
            'window_start' => $prior->window_start ?? null,
            'window_true' => (int) ($prior->window_true ?? 0),
            'window_false' => (int) ($prior->window_false ?? 0),
            'prev_start' => $prior->prev_start ?? null,
            'prev_true' => (int) ($prior->prev_true ?? 0),
            'prev_false' => (int) ($prior->prev_false ?? 0),
        ];

        // THE RUN CRITERION IS DECIDED HERE AND NOWHERE ELSE, because its input is the TRANSITION
        // and not the row: the prior branch is readable only from the row this write is about to
        // overwrite, so an alarm pass running later over the stored counts could not reconstruct
        // it — and because the verdict LATCHES (class docblock), a cold recomputation would
        // withdraw an alarm whose run had merely outgrown its own window.
        if ($criterion !== null && $criterion['kind'] === 'run') {
            $directionMet = $criterion['direction'] === 'either'
                || $branch === ($criterion['direction'] === 'true');

            if (! $directionMet) {
                // The run is on a branch this criterion does not alarm on. There is nothing to
                // carry and nothing to cross.
                $row['alarm_since'] = null;
            } else {
                // The latch, and the ONE event that discards it: this write breaking the run.
                $carried = $runContinues ? ($prior->alarm_since ?? null) : null;

                $row['alarm_since'] = $carried ?? (
                    $runLength >= $criterion['n']
                    && ($criterion['window_s'] === null
                        || $nowMs - Clock::toMs($runStartedAt) <= $criterion['window_s'] * 1000)
                        ? $nowSql : null
                );
            }
        }

        // ── THE TUMBLING WINDOW, maintained for the `share` kind alone ────────────────────────
        if ($criterion !== null && $criterion['kind'] === 'share') {
            $w = self::rolled($prior, $nowMs, $criterion['window_s'] * 1000);

            $row['window_start'] = Clock::fromMs($w['start']);
            $row['window_true'] = $w['true'] + ($branch ? $times : 0);
            $row['window_false'] = $w['false'] + ($branch ? 0 : $times);
            $row['prev_start'] = $w['prev_start'] === null ? null : Clock::fromMs($w['prev_start']);
            $row['prev_true'] = $w['prev_true'];
            $row['prev_false'] = $w['prev_false'];
        }

        DB::table('seat_predicates')->upsert(
            [$row],
            ['seat_ref', 'name'],
            ['true_count', 'false_count', 'last_true_at', 'last_false_at', 'alarm_since',
                'run_length', 'run_started_at', 'window_start', 'window_true', 'window_false',
                'prev_start', 'prev_true', 'prev_false'],
        );
    }

    /**
     * The branch the PREVIOUS evaluation took, read off the two last-seen timestamps.
     *
     * Null when the row has never been evaluated (no prior branch to be constant with) — which is
     * why a brand-new predicate cannot alarm on its very first `false`. § 5's smallest criterion is
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
     * The TUMBLING window state of a row, rolled forward to the window containing `$nowMs`.
     *
     * ⛔ ONE IMPLEMENTATION, TWO CALLERS, DELIBERATELY. `record()` calls it to WRITE the rolled
     * state; `share()` calls it to READ the effective last-completed window without writing. The
     * second caller is not a convenience — it is what stops a stale verdict latching: a predicate
     * whose writer has gone silent rolls to an EMPTY completed window on the READER's clock, and an
     * empty window is below every floor. A reader that trusted the stored `prev_*` would report a
     * forty-day-old incident for ever, which is over-fire two of the class docblock, re-minted.
     *
     * Windows are anchored on the row's FIRST evaluation, not on midnight: an anchor the row's own
     * history sets needs no time zone and no shared epoch.
     *
     * ⚠ AN EVALUATION WHOSE TIMESTAMP PRECEDES `window_start` IS COUNTED IN THE CURRENT WINDOW
     * rather than rolling anything back. It is reachable — the fold stamps an event's RECEIPT time
     * and can apply a backlog after a sweep pass has already stamped a later one — and the
     * alternative is re-opening a window that has already been reported on, which a tumbling store
     * cannot express and a rolling one is what card #7833 ruled against.
     *
     * @return array{start: int, true: int, false: int, prev_start: ?int, prev_true: int, prev_false: int}
     */
    private static function rolled(?object $prior, int $nowMs, int $windowMs): array
    {
        $start = $prior === null ? null : Clock::toMs($prior->window_start);

        if ($start === null) {
            return ['start' => $nowMs, 'true' => 0, 'false' => 0,
                'prev_start' => null, 'prev_true' => 0, 'prev_false' => 0];
        }

        $elapsed = $nowMs - $start;
        $rolls = $elapsed < $windowMs ? 0 : intdiv($elapsed, $windowMs);

        if ($rolls === 0) {
            return [
                'start' => $start,
                'true' => (int) $prior->window_true,
                'false' => (int) $prior->window_false,
                'prev_start' => Clock::toMs($prior->prev_start),
                'prev_true' => (int) $prior->prev_true,
                'prev_false' => (int) $prior->prev_false,
            ];
        }

        // ONE roll: the window that was current is the one that just completed. TWO OR MORE: at
        // least one whole window went by with no evaluation at all, so the window immediately
        // before the current one is EMPTY — which is the honest answer and not a gap to paper over.
        return [
            'start' => $start + $rolls * $windowMs,
            'true' => 0,
            'false' => 0,
            'prev_start' => $start + ($rolls - 1) * $windowMs,
            'prev_true' => $rolls === 1 ? (int) $prior->window_true : 0,
            'prev_false' => $rolls === 1 ? (int) $prior->window_false : 0,
        ];
    }

    /**
     * `docs/design/FLEET-STATE.md § 2.1`'s SEVENTH sweep job: the predicate-constant alarms.
     *
     * Runs over every row of `seat_predicates`, not over a list of names, so a predicate recorded
     * by a writer this class does not know about is still alarmed on. A row whose name has no
     * criterion is left alone rather than defaulted — a criterion nobody chose is a threshold
     * nobody can defend.
     *
     * ⛔ THE RETURN IS A REPORT, AND SINCE CARD #7833 IT IS ALSO REDUNDANT — WHICH IS THE POINT.
     * `Sweep::pass()` is the only caller and it discards the value. That used to LOSE information:
     * a `cannot_evaluate` row and a healthy row both carried a null `alarm_since`, so the one
     * per-seat surface an operator reads — § 8.2.3's `detail.predicates`, which publishes this
     * row's own columns — rendered a predicate NOBODY WAS CHECKING exactly like a predicate that
     * was fine. There is now no third outcome: every criterion answers, and after this pass
     * `alarm_since !== null` holds if and only if the outcome was `FIRES`, on every row visited.
     * The stored column therefore carries the whole verdict and the discarded return loses nothing
     * — asserted in `PredicateAlarmsTest`, not argued here.
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

            $outcomes[] = [
                'seat_ref' => (int) $row->seat_ref,
                'name' => (string) $row->name,
                'outcome' => $outcome,
            ];
        }

        return $outcomes;
    }

    /**
     * ONE ROW, ONE OF TWO ANSWERS. The class docblock argues the split; this is where it lands.
     *
     * @param  array<string, mixed>  $c
     */
    private static function outcome(array $c, object $row, int $nowMs): string
    {
        return match ($c['kind']) {
            // Decided at `record()` time, off the TRANSITION, and merely read here. Re-deciding it
            // from the stored row would answer a different question twice over: the counts cannot
            // reconstruct which branch the previous evaluation took, and the verdict LATCHES past
            // the point where a still-constant run outgrows its own window (class docblock).
            'run' => $row->alarm_since !== null ? self::FIRES : self::CLEAR,

            // § 5: "≥ 5 % server-closed across ≥ 1,000 in 24 h", over the last COMPLETED window,
            // rolled on the READER's clock so that a writer gone silent for a window clears.
            'share' => self::share($c, $row, $nowMs),

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
            // over the LIFETIME instead is a criterion § 5 does not state. An earlier revision
            // coded clause two as a live disjunct guarded by a liveness bound: that disjunct could
            // never decide a row either way, which is the decoration this repo refuses everywhere
            // else.
            'any_false' => $row->last_false_at !== null
                && $nowMs - Clock::toMs($row->last_false_at) <= $c['window_s'] * 1000
                    ? self::FIRES : self::CLEAR,

            default => throw new \LogicException('no alarm rule for predicate kind '.$c['kind']),
        };
    }

    /**
     * § 5's one SHARE criterion, over the last COMPLETED tumbling window.
     *
     * INTEGER CROSS-MULTIPLICATION, not `share_pct / 100`, so that "≥ 5 %" holds at every total by
     * construction. ⚠ The float form is NOT broken here and `CRITERIA` says so with the measurement
     * — this is robustness, not a bug fix, and it has no failing arm.
     *
     * @param  array<string, mixed>  $c
     */
    private static function share(array $c, object $row, int $nowMs): string
    {
        $w = self::rolled($row, $nowMs, $c['window_s'] * 1000);
        $total = $w['prev_true'] + $w['prev_false'];

        return $total >= $c['n'] && $w['prev_false'] * 100 >= $c['share_pct'] * $total
            ? self::FIRES : self::CLEAR;
    }
}

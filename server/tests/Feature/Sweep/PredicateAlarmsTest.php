<?php

namespace Tests\Feature\Sweep;

use App\Fold\Clock;
use App\Sweep\Predicates;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 5`'s alarm criteria — **which of them can be evaluated at all, and
 * what the ones that cannot do instead.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS FILE EXISTS SEPARATELY FROM `SweepJobsTest`. That file proves job 7 RUNS — that every
 * predicate has an evaluation site and both branch counts move. It never asked whether the ALARM
 * on top of those counts answers correctly, and it could not have noticed that four of the seven
 * criteria are not derivable from § 6.4's columns at all: an approximation that over-fires and a
 * criterion that refuses to answer produce identical `alarm_since` columns on a fixture whose
 * counts are small. Every check below is written against a history under which the earlier
 * approximation FIRED, so a refusal here is a discrimination rather than an absence.
 *
 * ⚠ THE HISTORIES ARE WRITTEN THROUGH `Predicates::record()`, THE REAL WRITER, IN BATCHES. § 5's
 * floors are 200, 1,000 and 5,760 evaluations; driving 5,760 sweep passes to reach one is not a
 * test, it is a stopwatch. `record()`'s `$times` parameter is the production path for "one physical
 * event is N evaluations" and it writes exactly the columns a real run would leave, so the fixture
 * inserts nothing and back-dates nothing. The alternative — writing `seat_predicates` rows directly
 * — would be the suite writing the table under test.
 */
class PredicateAlarmsTest extends SweepTestCase
{
    // ── the two over-fire scenarios the approximation actually produced ───────────────────────

    /**
     * OVER-FIRE ONE — **a sweeper outage made the very next evaluation an alarm.**
     *
     * `seat_live`'s criterion is "constant-`false` across ≥ 5,760 evaluations in a rolling 7 days".
     * The approximation conjoined a cumulative `false_count ≥ 5,760` with a WALL-CLOCK run length
     * of at least 5,760 × the 15 s cadence, and called the pair a windowed count. It is one only
     * while the sweeper is evaluating AT cadence — and § 2.2 devotes a row to a dead sweep worker,
     * so the condition that breaks it is one the design expects.
     *
     * The fixture is that condition: an ordinary history, then eight days in which the wall clock
     * advanced and no pass ran, then ONE evaluation. Under the approximation that single evaluation
     * satisfied a criterion demanding 5,760 of them.
     */
    public function test_a_sweep_outage_does_not_turn_the_next_evaluation_into_an_alarm(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->sweep();

        // An ordinary lifetime of `false` evaluations — the count a real seat accumulates over
        // weeks of going quiet at night, which is not by itself evidence about any window.
        Predicates::record($this->seatRef, 'seat_live', false, Clock::sql(now()), 5_760);

        $this->assertNotNull(
            $this->predicate('seat_live')->last_true_at,
            'the fixture needs a real prior `true` — the run length is measured from it',
        );

        // THE OUTAGE. The clock moves; the sweeper does not run, so no evaluation is recorded.
        $this->advanceServerClock(8 * 86_400);

        // …and this is the first pass back.
        $this->sweep();

        $this->assertNull(
            $this->predicate('seat_live')->alarm_since,
            'one evaluation after an outage is not 5,760 evaluations in a rolling 7 days',
        );

        $this->assertSame(
            Predicates::CANNOT_EVALUATE,
            $this->outcome('seat_live'),
            'the store cannot supply a windowed count, so the alarm must say so rather than guess',
        );
    }

    /**
     * OVER-FIRE TWO — **an old incident latched the alarm and no volume of clean work cleared it.**
     *
     * `call_closed_by_wire`'s criterion is "≥ 5 % server-closed across ≥ 1,000 in 24 h". The
     * approximation computed the share from CUMULATIVE counts and bounded it with "some evaluation
     * happened inside the window" — which is not "the window's own share crosses". The fixture is
     * the difference: a bad month long ago, then a large, entirely clean recent history. Every
     * evaluation inside the 24 h window is `true`; the cumulative share is 67 %.
     */
    public function test_a_months_old_incident_cannot_latch_the_share_alarm(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        // The incident: a thousand server closes, forty days ago.
        Predicates::record($this->seatRef, 'call_closed_by_wire', false, Clock::sql(now()), 1_000);

        $this->advanceServerClock(40 * 86_400);

        // Forty days of recovery — every close since has come off the wire.
        Predicates::record($this->seatRef, 'call_closed_by_wire', true, Clock::sql(now()), 500);

        $this->sweep();

        $row = $this->predicate('call_closed_by_wire');

        // 501 and not 500: the `cleanTurn()` fixture's own `tool.end` is a real wire close and the
        // fold recorded it. Counting it is the point — the fixture is a history, not a stub.
        $this->assertSame(501, (int) $row->true_count);
        $this->assertSame(1_000, (int) $row->false_count);
        $this->assertGreaterThan(
            0.05,
            (int) $row->false_count / ((int) $row->true_count + (int) $row->false_count),
            'the CUMULATIVE share is well past the criterion — which is what used to fire it',
        );
        $this->assertNull(
            $row->alarm_since,
            'the window contains 500 clean evaluations and no server close; a cumulative share is '
            .'not the criterion',
        );

        $this->assertSame(Predicates::CANNOT_EVALUATE, $this->outcome('call_closed_by_wire'));
    }

    // ── every windowed criterion, at the history that used to fire it ─────────────────────────

    /**
     * `activity_recent` is the CONSTANT kind in its `either` direction, and its 100 % end is the
     * one § 5 says "a receipt-fed activity column does exactly" — the discriminating-pair defect.
     * Under the approximation a lifetime of `true` with no `false` ever recorded took the
     * `oppositeAt === null` branch, which set the run length to `PHP_INT_MAX` and fired on the
     * cumulative count alone.
     */
    public function test_a_lifetime_of_one_branch_is_not_a_window_of_one_branch(): void
    {
        // The seat must be genuinely ACTIVE, so that the pass below records `true` as well. A
        // fixture whose own pass recorded `false` would stamp `last_false_at` and end the run — and
        // the check would then pass against the approximation too, which is a check that proves
        // nothing.
        $this->deliver($this->cleanTurn());
        $this->fold();

        Predicates::record($this->seatRef, 'activity_recent', true, Clock::sql(now()), 5_760);

        $this->assertNull(
            $this->predicate('activity_recent')->last_false_at,
            'the branch has never been taken — the approximation read that as an unbounded run',
        );

        $this->sweep();

        $this->assertNull(
            $this->predicate('activity_recent')->last_false_at,
            'the pass recorded `true` too, so the constancy run is still unbroken',
        );

        $this->assertNull($this->predicate('activity_recent')->alarm_since);
        $this->assertSame(Predicates::CANNOT_EVALUATE, $this->outcome('activity_recent'));
    }

    /**
     * `turn_clean` is the RATIO kind: "0 % or 100 % across ≥ 200 evaluations in a rolling 24 h".
     * 200 cumulative `true`s with a recent evaluation satisfied every term the approximation
     * checked — and § 5 is explicit that this criterion "can fire on a good seat", which is exactly
     * why it must not fire on evidence it does not have.
     */
    public function test_a_cumulative_hundred_percent_is_not_a_windowed_hundred_percent(): void
    {
        Predicates::record($this->seatRef, 'turn_clean', true, Clock::sql(now()), 200);

        $this->sweep();

        $this->assertNull($this->predicate('turn_clean')->alarm_since);
        $this->assertSame(Predicates::CANNOT_EVALUATE, $this->outcome('turn_clean'));
    }

    /**
     * All four at once, as a SET rather than one at a time — the same discipline
     * `test_job_7_all_seven_declared_predicates_have_a_writer` applies to the writers. A fifth
     * criterion quietly acquiring a windowed term, or one of these four quietly acquiring an
     * approximation again, is a change this assertion sees and a per-predicate test does not.
     */
    public function test_exactly_the_four_windowed_criteria_refuse_and_the_other_three_answer(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->advanceServerClock(61 * 60);
        $this->stayAlive();
        $this->sweep();

        $refusing = [];
        $answering = [];

        foreach ($this->outcomes() as $row) {
            if ($row['outcome'] === Predicates::CANNOT_EVALUATE) {
                $refusing[] = $row['name'];
            } else {
                $answering[] = $row['name'];
            }
        }

        sort($refusing);
        $answering = array_values(array_unique($answering));
        sort($answering);

        $this->assertSame(
            ['activity_recent', 'call_closed_by_wire', 'seat_live', 'turn_clean'],
            $refusing,
            '§ 5 states these four over a rolling window and § 6.4 carries no windowed count',
        );

        $this->assertSame(
            ['attention_resolved_by_wire', 'fold_current', 'ingest_receiving'],
            $answering,
            'the consecutive and existence criteria are exact and must still answer',
        );
    }

    // ── what a refusal does to the stored column ──────────────────────────────────────────────

    /**
     * `CANNOT_EVALUATE` writes NOTHING — it neither mints a verdict nor withdraws one.
     *
     * The half that needs an assertion is the second: a refusal that also CLEARED would be an
     * alarm silently withdrawn by a pass that had no opinion. The half that cannot happen is proved
     * rather than asserted — `alarm_since` has exactly two writers, `record()`'s `consecutive`
     * branch and `alarm()`'s two updates, so no row of a refusing kind can ever carry one. This
     * test drives the reachable half by putting the column in both states by hand and checking the
     * pass leaves each one where it found it.
     */
    public function test_a_refusal_neither_sets_nor_clears_the_stored_verdict(): void
    {
        Predicates::record($this->seatRef, 'turn_clean', true, Clock::sql(now()), 200);

        $this->sweep();
        $this->assertNull($this->predicate('turn_clean')->alarm_since);

        // The one state the proof above says is unreachable, forced, so that "leaves it alone" is
        // observed rather than inferred: a pass that cleared it would report a resolution nobody
        // established.
        $stamped = Clock::sql(now());
        DB::table('seat_predicates')
            ->where('seat_ref', $this->seatRef)->where('name', 'turn_clean')
            ->update(['alarm_since' => $stamped]);

        $this->advanceServerClock(15);
        $this->sweep();

        $this->assertSame(
            $stamped,
            $this->predicate('turn_clean')->alarm_since,
            'a pass with no opinion must not withdraw a verdict it did not make',
        );
    }

    // ── the control: the criteria that CAN be evaluated are still seen to fire and to clear ───

    /**
     * THE DISCRIMINATING CONTROL FOR THIS WHOLE FILE. Every check above asserts that an alarm did
     * NOT fire, and a suite of those alone passes just as well against an `alarm()` that returns
     * `CANNOT_EVALUATE` for everything, or does nothing at all. § 5's closing paragraph is the
     * standard — "each predicate has a criterion its own volume can reach, that it fires visibly,
     * and that it has been SEEN TO FIRE" — so both evaluable kinds are driven to fire here, and one
     * is driven back to clear.
     *
     * ⚠ AND WHAT THIS CONTROL CANNOT COVER, STATED RATHER THAN IMPLIED: after this change the four
     * windowed predicates CANNOT be seen to fire, because the ruling is that they must not answer
     * at all until § 6.4 carries a windowed count. "Seen to fire" for those four is owed by the
     * card that widens the schema, not by this one, and asserting a firing here would be asserting
     * the behaviour this file exists to remove.
     */
    public function test_the_evaluable_criteria_are_seen_to_fire_and_the_consecutive_one_to_clear(): void
    {
        // ANY_FALSE — `attention_resolved_by_wire`: "any server-ceiling resolution in 24 h".
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();
        $this->advanceServerClock(61 * 60);
        $this->stayAlive();
        $this->sweep();

        $this->assertNotNull($this->predicate('attention_resolved_by_wire')->alarm_since);
        $this->assertSame(Predicates::FIRES, $this->outcome('attention_resolved_by_wire'));

        // …and it CLEARS once the ceiling resolution ages out of its own 24 h window, which is what
        // separates this criterion from a latch.
        $this->advanceServerClock(25 * 3_600);
        $this->stayAlive();
        $this->sweep();

        $this->assertNull($this->predicate('attention_resolved_by_wire')->alarm_since);
        $this->assertSame(Predicates::CLEAR, $this->outcome('attention_resolved_by_wire'));

        // CONSECUTIVE — `fold_current`: two passes with the fold not running.
        $this->deliver($this->cleanTurn(), age: false);
        $this->advanceServerClock(120);
        $this->sweep();
        $this->advanceServerClock(15);
        $this->sweep();

        $this->assertSame(Predicates::FIRES, $this->outcome('fold_current'));
        $this->assertNotNull($this->predicate('fold_current')->alarm_since);

        $this->fold();
        $this->advanceServerClock(15);
        $this->sweep();

        $this->assertSame(Predicates::CLEAR, $this->outcome('fold_current'));
        $this->assertNull($this->predicate('fold_current')->alarm_since);
    }

    // ── rig ───────────────────────────────────────────────────────────────────────────────────

    /**
     * The outcome `alarm()` reports for one predicate on THIS seat, read by running the alarm pass.
     *
     * @return string one of `Predicates::FIRES` · `CLEAR` · `CANNOT_EVALUATE`
     */
    private function outcome(string $name): string
    {
        foreach ($this->outcomes() as $row) {
            if ($row['name'] === $name && $row['seat_ref'] === $this->seatRef) {
                return $row['outcome'];
            }
        }

        $this->fail('`'.$name.'` was not visited by the alarm pass at all');
    }

    /**
     * ⚠ RE-RUNS THE ALARM PASS. It is idempotent by construction — every branch is a function of
     * the stored row and the clock, and the two updates are guarded on the column already
     * disagreeing — so reading the outcomes cannot change them.
     *
     * @return list<array{seat_ref: int, name: string, outcome: string}>
     */
    private function outcomes(): array
    {
        $nowSql = Clock::sql(now());

        return Predicates::alarm(Clock::toMs($nowSql), $nowSql);
    }
}

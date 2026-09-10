<?php

namespace Tests\Feature\Sweep;

use App\Fold\Clock;
use App\Sweep\Predicates;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 5`'s alarm criteria — **each one seen to fire, seen not to fire,
 * and seen at its own boundary.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS FILE EXISTS SEPARATELY FROM `SweepJobsTest`. That file proves job 7 RUNS — that every
 * predicate has an evaluation site and both branch counts move. It never asked whether the ALARM
 * on top of those counts answers CORRECTLY, and it could not have noticed that four of the seven
 * criteria were not derivable from § 6.4's columns at all: an approximation that over-fires and a
 * criterion that refuses to answer produce identical `alarm_since` columns on a fixture whose
 * counts are small.
 *
 * WHAT CHANGED, AND WHAT IT OWES (card #7833). § 6.4 now carries `run_length` / `run_started_at`
 * and a tumbling `window_*` / `prev_*` pair, so all seven criteria are evaluated and the interim
 * `cannot_evaluate` outcome is gone. § 5's closing standard is that each predicate "has a
 * criterion its own volume can reach, that it fires visibly, and that it has been **seen to
 * fire**" — AT-D2-13 — so every criterion below is driven BOTH ways across its own boundary. A
 * suite of "did not fire" assertions passes just as well against an alarm that does nothing.
 *
 * ⚠ THE HISTORIES ARE WRITTEN THROUGH `Predicates::record()`, THE REAL WRITER, IN BATCHES. § 5's
 * floors are 200, 1,000 and 5,760 evaluations; driving 5,760 sweep passes to reach one is not a
 * test, it is a stopwatch. `record()`'s `$times` parameter is the production path for "one physical
 * event is N evaluations" and it writes exactly the columns a real run would leave, so the fixture
 * inserts nothing and back-dates nothing. The alternative — writing `seat_predicates` rows directly
 * — would be the suite writing the table under test.
 *
 * ⚠ AND A BATCH IS ONE INSTANT, which matters to a criterion with a window: N evaluations written
 * in one call all fall inside any window at all. Every fixture below that needs a history SPREAD
 * over time therefore writes it in chunks with the clock moved between them, and that difference
 * is the whole content of the H1/H2 pair.
 */
class PredicateAlarmsTest extends SweepTestCase
{
    // ── the pair that proves the OLD store could not answer, and the new one can ──────────────

    /**
     * ⭐ **H1 vs H2 — TWO HISTORIES, OPPOSITE TRUTH VALUES, ONE BYTE-IDENTICAL ROW.**
     *
     * `turn_clean`'s criterion is "0 % or 100 % across ≥ 200 evaluations in a rolling 24 h".
     *
     *   H1  250 clean turns, all inside one day                      → criterion **met**
     *   H2  249 clean turns spread over a month, plus one an hour ago → criterion **NOT met**
     *
     * Against § 6.4's five original columns both rows read `true_count = 250`, `false_count = 0`,
     * `last_true_at` an hour ago, `last_false_at` NULL, `alarm_since` NULL — and no function of
     * that tuple separates them, which is why the criterion was unevaluable rather than merely
     * approximated. This test asserts BOTH halves: that the five old columns still agree exactly,
     * and that the alarms now disagree.
     */
    public function test_two_histories_with_one_identical_legacy_row_get_opposite_verdicts(): void
    {
        $h1 = $this->seatRef;
        [, $h2] = $this->issueToken(self::INSTALL, 'aimla-impl');

        // H2 — 249 clean turns SPREAD across three days. No 24 h window holds 200 of them, and the
        // run that does hold 249 began two days before it got there.
        foreach ([83, 83, 83] as $chunk) {
            Predicates::record($h2, 'turn_clean', true, Clock::sql(now()), $chunk);
            $this->advanceServerClock(86_400);
        }

        $this->assertNull(
            $this->predicate('turn_clean', $h2)->alarm_since,
            '249 clean turns over three days is not 200 in a rolling 24 h',
        );

        // …a month passes with no turns at all.
        $this->advanceServerClock(27 * 86_400);

        // H1 — the same 249 clean turns, INSIDE one day: its whole history starts here.
        Predicates::record($h1, 'turn_clean', true, Clock::sql(now()), 249);

        $this->assertNotNull(
            $this->predicate('turn_clean', $h1)->alarm_since,
            '249 clean turns inside one day IS 200 in a rolling 24 h',
        );

        // Both seats take one more clean turn at the SAME instant, three hours later, so the two
        // rows' `last_true_at` are identical to the millisecond.
        $this->advanceServerClock(3 * 3_600);
        Predicates::record($h1, 'turn_clean', true, Clock::sql(now()));
        Predicates::record($h2, 'turn_clean', true, Clock::sql(now()));

        $a = $this->predicate('turn_clean', $h1);
        $b = $this->predicate('turn_clean', $h2);

        // THE OLD ROW, COLUMN BY COLUMN — identical, which is the defect card #7833 exists to end.
        foreach (['true_count', 'false_count', 'last_true_at', 'last_false_at'] as $column) {
            $this->assertSame(
                $a->$column, $b->$column,
                'H1 and H2 must be indistinguishable in `'.$column.'` — that is the premise',
            );
        }

        $this->assertSame(250, (int) $a->true_count);
        $this->assertSame(0, (int) $a->false_count);

        // THE NEW ROW — the run is what separates them, and it separates them exactly.
        $this->assertSame(250, (int) $a->run_length);
        $this->assertSame(250, (int) $b->run_length);
        $this->assertNotSame(
            $a->run_started_at, $b->run_started_at,
            'the run LENGTH is equal; the run START is the discriminating column',
        );

        $this->assertNotNull($a->alarm_since, 'H1 met the criterion');
        $this->assertNull($b->alarm_since, 'H2 never met it');

        $this->assertSame(Predicates::FIRES, $this->outcome('turn_clean', $h1));
        $this->assertSame(Predicates::CLEAR, $this->outcome('turn_clean', $h2));
    }

    // ── the two over-fire scenarios the earlier approximation actually produced ───────────────

    /**
     * OVER-FIRE ONE — **a sweeper outage made the very next evaluation an alarm.**
     *
     * `seat_live`'s criterion is "constant-`false` across ≥ 5,760 evaluations in a rolling 7 days".
     * The approximation conjoined a CUMULATIVE `false_count ≥ 5,760` with a wall-clock run length
     * and called the pair a windowed count. It is one only while the sweeper is evaluating AT
     * cadence — and § 2.2 devotes a row to a dead sweep worker, so the condition that breaks it is
     * one the design expects.
     *
     * The fixture is that condition, and the history is deliberately NOT one run: an ordinary
     * lifetime of `false` evaluations BROKEN by the two nights the seat came back, which is what a
     * real seat accumulates. Its cumulative `false_count` clears 5,760; its longest run does not.
     * Then eight days in which the clock advanced and no pass ran, then ONE evaluation.
     */
    public function test_a_sweep_outage_does_not_turn_the_next_evaluation_into_an_alarm(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->sweep();

        foreach ([[false, 2_000], [true, 1], [false, 2_000], [true, 1], [false, 1_760]] as [$branch, $times]) {
            Predicates::record($this->seatRef, 'seat_live', $branch, Clock::sql(now()), $times);
            $this->advanceServerClock(60);
        }

        $this->assertGreaterThanOrEqual(
            5_760,
            (int) $this->predicate('seat_live')->false_count,
            'the fixture needs a CUMULATIVE count past the criterion — that is what used to fire',
        );

        // THE OUTAGE. The clock moves; the sweeper does not run, so no evaluation is recorded.
        $this->advanceServerClock(8 * 86_400);

        // …and this is the first pass back. The seat is long stale, so the pass records `false`.
        $this->sweep();

        $row = $this->predicate('seat_live');

        $this->assertSame(1_761, (int) $row->run_length, 'the run is what the criterion counts');
        $this->assertNull(
            $row->alarm_since,
            'one evaluation after an outage is not 5,760 evaluations in a rolling 7 days',
        );
        $this->assertSame(Predicates::CLEAR, $this->outcome('seat_live'));
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

        // Forty days is forty rolls of a 24 h window, so the window immediately before the current
        // one is EMPTY — not the incident's.
        $this->assertSame(0, (int) $row->prev_true);
        $this->assertSame(0, (int) $row->prev_false);
        $this->assertNull($row->alarm_since);
        $this->assertSame(Predicates::CLEAR, $this->outcome('call_closed_by_wire'));
    }

    // ── `seat_live` — the run floor, the window bound, and the direction ──────────────────────

    /** SEEN TO FIRE, and seen NOT to fire one evaluation short of its own floor. */
    public function test_seat_live_fires_at_5760_and_not_at_5759(): void
    {
        Predicates::record($this->seatRef, 'seat_live', false, Clock::sql(now()), 5_759);

        $this->assertNull($this->predicate('seat_live')->alarm_since, '5,759 is not 5,760');
        $this->assertSame(Predicates::CLEAR, $this->outcome('seat_live'));

        Predicates::record($this->seatRef, 'seat_live', false, Clock::sql(now()));

        $this->assertSame(5_760, (int) $this->predicate('seat_live')->run_length);
        $this->assertNotNull($this->predicate('seat_live')->alarm_since);
        $this->assertSame(Predicates::FIRES, $this->outcome('seat_live'));

        // …and one `true` withdraws it. An alarm that cannot clear is one that gets trained away.
        Predicates::record($this->seatRef, 'seat_live', true, Clock::sql(now()));

        $this->assertSame(1, (int) $this->predicate('seat_live')->run_length);
        $this->assertNull($this->predicate('seat_live')->alarm_since);
        $this->assertSame(Predicates::CLEAR, $this->outcome('seat_live'));
    }

    /**
     * THE WINDOW BOUND, AT ITS OWN EDGE — two seats, the same 5,760-evaluation run, one begun
     * exactly 7 days ago and one a second earlier. § 5's criterion is "in a rolling 7 days", so the
     * first is inside it and the second is not.
     */
    public function test_the_run_window_is_inclusive_at_seven_days_and_excludes_a_second_more(): void
    {
        $inside = $this->seatRef;
        [, $outside] = $this->issueToken(self::INSTALL, 'aimla-impl');

        Predicates::record($outside, 'seat_live', false, Clock::sql(now()), 5_759);
        $this->advanceServerClock(1);
        Predicates::record($inside, 'seat_live', false, Clock::sql(now()), 5_759);

        $this->advanceServerClock(7 * 86_400);

        Predicates::record($inside, 'seat_live', false, Clock::sql(now()));
        Predicates::record($outside, 'seat_live', false, Clock::sql(now()));

        $this->assertSame(5_760, (int) $this->predicate('seat_live', $inside)->run_length);
        $this->assertSame(5_760, (int) $this->predicate('seat_live', $outside)->run_length);

        $this->assertNotNull(
            $this->predicate('seat_live', $inside)->alarm_since,
            'a run that began exactly 7 days ago is inside a rolling 7 days',
        );
        $this->assertNull(
            $this->predicate('seat_live', $outside)->alarm_since,
            'a run that began 7 days and one second ago is not',
        );
    }

    /**
     * THE DIRECTION, WHICH IS A CRITERION AND NOT A DEFAULT. § 5: constant-`true` is deliberately
     * NOT a criterion for `seat_live` — "a fleet in which no seat is ever stale for a week is the
     * good outcome, and an alarm that fires on the healthy case is worse than no alarm". A run of
     * 5,760 `true`s is the healthy case and must be silent, where the same run of `false`s fires.
     */
    public function test_seat_live_never_fires_on_a_constant_true_run(): void
    {
        Predicates::record($this->seatRef, 'seat_live', true, Clock::sql(now()), 20_000);

        $this->assertSame(20_000, (int) $this->predicate('seat_live')->run_length);
        $this->assertNull($this->predicate('seat_live')->alarm_since);
        $this->assertSame(Predicates::CLEAR, $this->outcome('seat_live'));
    }

    // ── `activity_recent` — the `either` direction, both ends seen ────────────────────────────

    /**
     * § 5 gives this predicate BOTH directions and says why each is wrong: constant-`true` "means a
     * seat has done something in the activity set every 15 minutes for a week without a single
     * quiet quarter-hour, which no real desk does AND A RECEIPT-FED ACTIVITY COLUMN DOES EXACTLY";
     * constant-`false` means a week with no activity at all on a seat still reporting. Both ends
     * are driven here, on two seats, plus the mixed control that must stay silent.
     */
    public function test_activity_recent_fires_at_both_ends_and_not_on_a_mixed_history(): void
    {
        $constant = $this->seatRef;
        [, $mixed] = $this->issueToken(self::INSTALL, 'aimla-impl');

        // The 100 % end — the receipt-fed defect.
        Predicates::record($constant, 'activity_recent', true, Clock::sql(now()), 5_760);
        $this->assertNotNull($this->predicate('activity_recent', $constant)->alarm_since);
        $this->assertSame(Predicates::FIRES, $this->outcome('activity_recent', $constant));

        // The 0 % end, on the same row: one `false` breaks the run and withdraws the verdict, and
        // 5,760 more re-establish it the other way.
        //
        // ⚠ THE CLOCK MOVES BEFORE THE BRANCH FLIPS, and it has to. `priorBranch()` reads the run's
        // branch off the two STORED last-seen timestamps and resolves an exact tie toward `true` —
        // "a tie resolves toward not alarming" — so a `false` written in the same millisecond as
        // the last `true` leaves `last_true_at == last_false_at` and the next `false` cannot
        // continue the run. A real seat's evaluations are 15 s apart; only a fixture can tie.
        $this->advanceServerClock(15);
        Predicates::record($constant, 'activity_recent', false, Clock::sql(now()));
        $this->assertNull($this->predicate('activity_recent', $constant)->alarm_since);

        Predicates::record($constant, 'activity_recent', false, Clock::sql(now()), 5_759);
        $this->assertNotNull($this->predicate('activity_recent', $constant)->alarm_since);
        $this->assertSame(Predicates::FIRES, $this->outcome('activity_recent', $constant));

        // THE NEGATIVE CONTROL — the same VOLUME, mixed. AT-D2-13 names it in terms.
        for ($i = 0; $i < 20; $i++) {
            Predicates::record($mixed, 'activity_recent', true, Clock::sql(now()), 500);
            $this->advanceServerClock(15);
            Predicates::record($mixed, 'activity_recent', false, Clock::sql(now()), 500);
            $this->advanceServerClock(15);
        }

        $this->assertSame(20_000, (int) $this->predicate('activity_recent', $mixed)->true_count
            + (int) $this->predicate('activity_recent', $mixed)->false_count);
        $this->assertNull($this->predicate('activity_recent', $mixed)->alarm_since);
        $this->assertSame(Predicates::CLEAR, $this->outcome('activity_recent', $mixed));
    }

    // ── `turn_clean` — the floor, and the LATCH ───────────────────────────────────────────────

    /** SEEN TO FIRE at 200, and seen not to at 199. */
    public function test_turn_clean_fires_at_200_and_not_at_199(): void
    {
        Predicates::record($this->seatRef, 'turn_clean', true, Clock::sql(now()), 199);

        $this->assertNull($this->predicate('turn_clean')->alarm_since);
        $this->assertSame(Predicates::CLEAR, $this->outcome('turn_clean'));

        Predicates::record($this->seatRef, 'turn_clean', true, Clock::sql(now()));

        $this->assertNotNull($this->predicate('turn_clean')->alarm_since);
        $this->assertSame(Predicates::FIRES, $this->outcome('turn_clean'));
    }

    /**
     * ⛔ **THE LATCH, AND IT IS LOAD-BEARING RATHER THAN AN OPTIMISATION.**
     *
     * A run that crosses its floor INSIDE the window and then keeps going OUTGROWS the window —
     * `now − run_started_at` passes 24 h while the run is still perfectly constant. The verdict is
     * decided at `record()` and latched in `alarm_since` precisely so that a later pass cannot
     * withdraw an alarm whose subject has not changed; a rule recomputed cold in `alarm()` would.
     *
     * The one event that DOES withdraw it is the run breaking, and that half is driven too — a
     * latch with no exit is the stuck alarm § 5 is written against.
     */
    public function test_a_fired_run_stays_fired_when_it_outgrows_its_own_window(): void
    {
        Predicates::record($this->seatRef, 'turn_clean', true, Clock::sql(now()), 200);
        $stamped = $this->predicate('turn_clean')->alarm_since;
        $this->assertNotNull($stamped);

        // Two days of the same unbroken clean run. The run's START is now well outside 24 h.
        $this->advanceServerClock(2 * 86_400);
        Predicates::record($this->seatRef, 'turn_clean', true, Clock::sql(now()));

        $this->assertSame(201, (int) $this->predicate('turn_clean')->run_length);

        // The alarm pass runs FIRST and the column is read after it, so "did not withdraw" is
        // observed rather than inferred.
        $this->assertSame(Predicates::FIRES, $this->outcome('turn_clean'));
        $this->assertSame(
            $stamped,
            $this->predicate('turn_clean')->alarm_since,
            'a still-constant run that outgrew its window must not have its alarm withdrawn',
        );

        // …and the ONE event that withdraws it: an aborted turn breaks the run.
        Predicates::record($this->seatRef, 'turn_clean', false, Clock::sql(now()));

        $this->assertSame(1, (int) $this->predicate('turn_clean')->run_length);
        $this->assertNull($this->predicate('turn_clean')->alarm_since);
        $this->assertSame(Predicates::CLEAR, $this->outcome('turn_clean'));
    }

    // ── `call_closed_by_wire` — the share, over the last COMPLETED window ─────────────────────

    /**
     * SEEN TO FIRE AT EXACTLY ITS OWN THRESHOLD. § 5 says "≥ 5 % server-closed across ≥ 1,000", so
     * 50 in 1,000 is the boundary this criterion states, and a threshold unreachable at its own
     * value is a decoration — 50 fires, 49 does not, one evaluation apart.
     *
     * ⚠ WHAT THIS ARM DOES **NOT** PROVE, said here because the code it covers invites the
     * inference: it does not hold `share()`'s integer cross-multiplication against a float. The
     * float form is not broken at this boundary — `0.05 * 1000` is exactly 50.0 — and `CRITERIA`
     * records the measurement. The integer form is exact by construction and has no failing case.
     */
    public function test_the_share_fires_at_exactly_fifty_in_a_thousand_and_not_at_forty_nine(): void
    {
        $at = $this->seatRef;
        [, $under] = $this->issueToken(self::INSTALL, 'aimla-impl');

        Predicates::record($at, 'call_closed_by_wire', true, Clock::sql(now()), 950);
        Predicates::record($at, 'call_closed_by_wire', false, Clock::sql(now()), 50);

        Predicates::record($under, 'call_closed_by_wire', true, Clock::sql(now()), 951);
        Predicates::record($under, 'call_closed_by_wire', false, Clock::sql(now()), 49);

        // THE WINDOW HAS NOT COMPLETED YET, so neither answers on it. This is the tumbling
        // discipline itself, and the accepted cost § 5 records: the alarm arrives at the roll.
        $this->assertSame(Predicates::CLEAR, $this->outcome('call_closed_by_wire', $at));
        $this->assertSame(Predicates::CLEAR, $this->outcome('call_closed_by_wire', $under));

        $this->advanceServerClock(86_400);

        $this->assertSame(
            Predicates::FIRES,
            $this->outcome('call_closed_by_wire', $at),
            '50 in 1,000 IS 5 % — a threshold unreachable at its own value is a decoration',
        );
        $this->assertSame(Predicates::CLEAR, $this->outcome('call_closed_by_wire', $under));

        // The verdict reached the stored column, which is the only surface a consumer reads.
        $this->assertNotNull($this->predicate('call_closed_by_wire', $at)->alarm_since);
        $this->assertNull($this->predicate('call_closed_by_wire', $under)->alarm_since);
    }

    /** THE FLOOR, at its own edge: 999 server closes out of 999 is 100 % and still below 1,000. */
    public function test_the_share_floor_holds_at_999_and_releases_at_1000(): void
    {
        $short = $this->seatRef;
        [, $exact] = $this->issueToken(self::INSTALL, 'aimla-impl');

        Predicates::record($short, 'call_closed_by_wire', false, Clock::sql(now()), 999);
        Predicates::record($exact, 'call_closed_by_wire', false, Clock::sql(now()), 1_000);

        $this->advanceServerClock(86_400);

        $this->assertSame(
            Predicates::CLEAR,
            $this->outcome('call_closed_by_wire', $short),
            '999 evaluations is below § 5\'s floor however bad the share is',
        );
        $this->assertSame(Predicates::FIRES, $this->outcome('call_closed_by_wire', $exact));
    }

    /**
     * ⚠ **THE ACCEPTED COST OF A TUMBLING WINDOW, PINNED AS A TEST RATHER THAN LEFT AS PROSE.**
     *
     * § 5 records it: a burst of server closes that STRADDLES a window boundary can leave both
     * halves under the 1,000 floor and alarm on neither, and detection can only arrive at a roll.
     * That is under-firing, which is the direction § 5's own trade prefers — but it is a real cost
     * the operator accepted, so it is asserted here. An implementer who later makes this fire is
     * changing the ruled design, not fixing a bug, and this arm is what tells them so.
     */
    public function test_a_burst_that_straddles_the_boundary_alarms_on_neither_half(): void
    {
        // 700 server closes at the end of one window…
        Predicates::record($this->seatRef, 'call_closed_by_wire', false, Clock::sql(now()), 700);
        $this->advanceServerClock(86_400);

        // …and 700 more at the start of the next. 1,400 in 24 h of wall clock, 100 % server-closed.
        Predicates::record($this->seatRef, 'call_closed_by_wire', false, Clock::sql(now()), 700);
        $this->advanceServerClock(86_400);

        $this->assertSame(
            Predicates::CLEAR,
            $this->outcome('call_closed_by_wire'),
            'neither completed window reached 1,000 — the recorded, accepted cost of tumbling',
        );
    }

    // ── the whole set: every criterion answers, and the stored column carries the answer ──────

    /**
     * ALL SEVEN, AS A SET rather than one at a time — the same discipline
     * `test_job_7_all_seven_declared_predicates_have_a_writer` applies to the writers.
     *
     * This assertion replaces the one that used to name the four criteria that REFUSED. A criterion
     * quietly reacquiring a refusal, or a new one arriving with no rule, is a change this sees and
     * a per-predicate test does not.
     */
    public function test_every_declared_predicate_answers_and_none_refuses(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->advanceServerClock(61 * 60);
        $this->stayAlive();
        $this->sweep();

        $answered = [];

        foreach ($this->outcomes() as $row) {
            $this->assertContains(
                $row['outcome'], [Predicates::FIRES, Predicates::CLEAR],
                '`'.$row['name'].'` returned an outcome that is neither verdict',
            );
            $answered[] = $row['name'];
        }

        sort($answered);
        $expected = Predicates::names();
        sort($expected);

        $this->assertSame(
            $expected, array_values(array_unique($answered)),
            'every predicate § 5 declares must be visited and answered by the alarm pass',
        );
    }

    /**
     * ⭐ **THE STORED COLUMN IS THE WHOLE VERDICT — the gap card #7833 closes on the READ side.**
     *
     * `Sweep::pass()` discards `Predicates::alarm()`'s return, and § 8.2.3's `detail.predicates`
     * publishes the row's own columns and nothing else. While a third outcome existed that wrote
     * NOTHING, a predicate nobody was checking rendered exactly like a healthy one. It is closed by
     * REMOVING the third outcome rather than by plumbing a new field, and that closure is only true
     * if `alarm_since` and the outcome agree on every row of every pass — which is what this
     * asserts, over a fixture that contains at least one of each verdict so the check can fail.
     */
    public function test_the_stored_alarm_column_agrees_with_every_returned_outcome(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->advanceServerClock(61 * 60);
        $this->stayAlive();
        $this->sweep();

        $verdicts = [];

        foreach ($this->outcomes() as $row) {
            $stored = DB::table('seat_predicates')
                ->where('seat_ref', $row['seat_ref'])->where('name', $row['name'])
                ->value('alarm_since');

            $this->assertSame(
                $row['outcome'] === Predicates::FIRES,
                $stored !== null,
                '`'.$row['name'].'` reports '.$row['outcome'].' and stores '.var_export($stored, true),
            );

            $verdicts[$row['outcome']] = true;
        }

        // THE CONTROL: an agreement that only ever compares two nulls would pass against an alarm
        // that never fires at all.
        $this->assertArrayHasKey(Predicates::FIRES, $verdicts, 'the fixture must contain a firing row');
        $this->assertArrayHasKey(Predicates::CLEAR, $verdicts, 'and a clear one');
    }

    // ── the two criteria that were always exact, still seen to fire and to clear ──────────────

    /**
     * `any_false` and the no-window run criterion, driven through a fire and a clear apiece. They
     * were the only two evaluable before card #7833 and they must not have regressed under the
     * rule that absorbed the `consecutive` kind into the run kind.
     */
    public function test_the_existence_and_consecutive_criteria_still_fire_and_clear(): void
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

        // RUN, no window — `fold_current`: two passes with the fold not running is a run of 2.
        $this->deliver($this->cleanTurn(), age: false);
        $this->advanceServerClock(120);
        $this->sweep();

        $this->assertSame(1, (int) $this->predicate('fold_current')->run_length);
        $this->assertSame(Predicates::CLEAR, $this->outcome('fold_current'), 'one pass is not two');

        $this->advanceServerClock(15);
        $this->sweep();

        $this->assertSame(2, (int) $this->predicate('fold_current')->run_length);
        $this->assertSame(Predicates::FIRES, $this->outcome('fold_current'));
        $this->assertNotNull($this->predicate('fold_current')->alarm_since);

        $this->fold();
        $this->advanceServerClock(15);
        $this->sweep();

        $this->assertSame(Predicates::CLEAR, $this->outcome('fold_current'));
        $this->assertNull($this->predicate('fold_current')->alarm_since);
    }

    /**
     * The batching guard, kept from the `consecutive` kind it came from and RE-ARGUED rather than
     * carried: a run now absorbs a batch of N correctly, so the guard is no longer arithmetic. What
     * it asserts is that these two predicates have no batching caller — both are per-pass, one per
     * seat and one fleet-wide — so a `$times` above 1 is a wiring mistake that must raise.
     */
    public function test_a_per_pass_predicate_refuses_to_be_batched(): void
    {
        $this->expectException(\LogicException::class);

        Predicates::record($this->seatRef, 'fold_current', false, Clock::sql(now()), 2);
    }

    // ── rig ───────────────────────────────────────────────────────────────────────────────────

    /**
     * The outcome `alarm()` reports for one predicate on one seat, read by running the alarm pass.
     *
     * @return string one of `Predicates::FIRES` · `CLEAR`
     */
    private function outcome(string $name, ?int $seatRef = null): string
    {
        $seatRef ??= $this->seatRef;

        foreach ($this->outcomes() as $row) {
            if ($row['name'] === $name && $row['seat_ref'] === $seatRef) {
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

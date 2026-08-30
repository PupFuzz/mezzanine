<?php

namespace Tests\Feature\Fold;

use App\Fold\Badges;
use App\Fold\Clock;
use App\Fold\SeatFacts;
use App\Sweep\Sweep;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-21 — **a frozen fold cannot look healthy.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE DEGRADATION THIS TEST IS FOR. § 2.3: "Receipts are written by the INGEST; derived state is
 * written by the FOLD. If the fold stops while the ingest keeps working, `last_receipt_at` keeps
 * moving and the desk keeps showing whatever it was doing when derivation stopped. That is a floor
 * that looks alive and is lying — the precise failure § 3 exists to forbid, arriving through the
 * derivation plane instead of through a timestamp." It is "the one degradation in this design that
 * is invisible without a deliberate instrument".
 *
 * ⚠ SCOPE — THE FEED-SIDE HALF OF § 11's GREEN IS NOT ASSERTED HERE AND THE SEAM IS NOT CROSSED.
 * AT-D2-21's GREEN also says "`fleet.fold` reports `stalled`" and "the REST SNAPSHOT still serves
 * (`derivation.fold_lag_ms` rising per seat)". Both are § 8.2 surfaces and belong to card #7339
 * PART B, which is not built. What this file asserts instead is every fact those surfaces would
 * READ — the computed lag, the badge, the episode counter, the `fold_current` predicate — at the
 * one home each of them has, so that Part B composes on them rather than re-deriving them. The
 * unasserted halves are named in the PR body rather than approximated here.
 */
class At21FrozenFoldTest extends FoldTestCase
{
    /**
     * GREEN — "within 60 s every affected seat badges `fold_lag`".
     *
     * WITHIN 60 s IS WHY THE SWEEPER IS THE WRITER. § 2.1 runs it every 15 s; the fold cannot raise
     * this badge on a frozen fold because a frozen fold is not running, and no read surface can,
     * because § 7.2 counts `fold_lag_alarm_entered` "once per lag EPISODE" and an episode is a
     * thing that happens on a clock rather than when somebody fetches a snapshot.
     */
    public function test_a_paused_fold_badges_fold_lag_within_sixty_seconds_and_counts_the_episode(): void
    {
        // The fold runs once so the seat has real history, then STOPS. The ingest keeps accepting.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame([], json_decode($this->state()->server_badges, true));

        $this->deliver($this->heartbeats(1));   // the ingest keeps working; the fold does not run

        $this->advanceServerClock(61);
        $this->sweep();

        $this->assertContains('fold_lag', json_decode($this->state()->server_badges, true));
        $this->assertSame(1, $this->counter('fold_lag_alarm_entered'));

        // ONCE PER EPISODE, not once per pass: § 7.2's counter is an episode counter and 5,760
        // passes a seat-day would otherwise make it a duration in units of 15 s.
        $this->advanceServerClock(15);
        $this->sweep();
        $this->assertSame(1, $this->counter('fold_lag_alarm_entered'));

        // § 5's `fold_current`: "pause the fold daemon → `false` within one pass".
        $predicate = DB::table('seat_predicates')
            ->where('seat_ref', $this->seatRef)->where('name', 'fold_current')->first();
        $this->assertGreaterThan(0, (int) $predicate->false_count);
        $this->assertNotNull($predicate->alarm_since, 'constant-false for 2 consecutive passes');
    }

    /**
     * GREEN — "Resume the daemon → the badges clear and the states converge to what an
     * uninterrupted run would have produced (assert against a CONTROL RUN)."
     */
    public function test_resuming_the_fold_clears_the_badge_and_converges_on_the_control_run(): void
    {
        $this->deliver($this->cleanTurn());
        $this->deliver($this->blockedPair());

        $this->advanceServerClock(200);
        $this->sweep();
        $this->assertContains('fold_lag', json_decode($this->state()->server_badges, true));

        $this->fold();
        $this->advanceServerClock(15);
        $this->sweep();

        $this->assertNotContains('fold_lag', json_decode($this->state()->server_badges, true));
        $this->assertSame(0, SeatFacts::foldLagMs($this->state(), $this->nowMs()));

        // The control run: a second seat, the SAME fixtures, folded as they arrive. The axes and
        // the open-fact pointers must match field for field. `state_version` and the derivation
        // bookkeeping are excluded by name for the reason AT-D2-10 excludes them: the interrupted
        // seat took a different NUMBER of steps to reach the same place, which is the property
        // under test rather than a divergence.
        [$token, $controlRef] = $this->issueToken('aimla', 'aimla-control');

        $this->deliver($this->cleanTurn('control-session'), token: $token, seat: 'aimla-control');
        $this->fold();
        $this->deliver($this->blockedPair(), token: $token, seat: 'aimla-control');
        $this->fold();

        $compare = ['render_state', 'link_state', 'activity_state', 'unknown_reason',
            'open_calls', 'open_turn', 'server_badges'];

        foreach ($compare as $column) {
            $this->assertSame(
                DB::table('seat_state')->where('seat_ref', $controlRef)->value($column),
                DB::table('seat_state')->where('seat_ref', $this->seatRef)->value($column),
                $column.' diverged from the uninterrupted control run',
            );
        }
    }

    /**
     * GREEN — THE NEVER-FOLDED SEAT. "With the fold still paused, deliver a PROVISIONED SEAT'S VERY
     * FIRST BATCH. Its cursor has never been advanced, so this is the one state in which the fold
     * has written nothing for the lag to be measured from."
     *
     * This is § 2.3's ingest-seeded cursor clock, "the reachable state a read-time `COALESCE` would
     * have rendered as CAUGHT UP".
     */
    public function test_a_never_folded_seats_lag_is_a_number_that_rises_from_its_first_batchs_receipt(): void
    {
        $this->assertSame(0, (int) $this->state()->fold_cursor_event_id);
        $this->assertNull($this->state()->fold_cursor_received_at);

        // Before the first batch: `head_event_id` is 0, so the CURSOR TEST pins the lag to 0 and a
        // provisioned-but-silent seat never badges. That is the branch that makes the null
        // unreachable rather than handled.
        $this->assertSame(0, SeatFacts::foldLagMs($this->state(), $this->nowMs()));

        $this->deliver($this->cleanTurn());
        $seeded = $this->state()->fold_cursor_received_at;

        $this->assertNotNull($seeded, 'the INGEST seeds the cursor clock on the seat\'s first event');

        $this->advanceServerClock(61);
        $this->sweep();

        // A NUMBER AND NOT NULL, and it rises from the batch's own `received_at`. On a never-folded
        // seat "the oldest unfolded event IS the seat's first event, and the first batch's
        // `received_at` is that event's own receipt time — the lag it yields is EXACT rather than
        // an upper bound" (§ 2.3).
        $lag = SeatFacts::foldLagMs($this->state(), $this->nowMs());
        $this->assertIsInt($lag);
        $this->assertSame($this->nowMs() - Clock::toMs($seeded), $lag);
        $this->assertGreaterThan(Badges::FOLD_LAG_MS, $lag);

        // "…that the seat badges `fold_lag` at 60 s LIKE ANY OTHER".
        $this->assertContains('fold_lag', json_decode($this->state()->server_badges, true));
    }

    /**
     * FOURTH RED — THE UNSEEDED CURSOR. "Drop the ingest's one-shot seed of
     * `fold_cursor_received_at` and re-run the GREEN above → `server_now − NULL` yields no value, a
     * non-null wire field serializes `null`, `fold_current` records neither branch on the seat the
     * alarm exists for, and `fleet.max_fold_lag_ms` aggregates over a hole."
     *
     * The RED is DRIVEN rather than described: the seed is nulled on a seat whose `head_event_id`
     * is already above 0, which is exactly the state dropping the ingest's seed would leave, and
     * the assertion is that the lag REFUSES rather than returning a number. § 2.3 rejects the
     * read-time `COALESCE` in terms — "the fallback would have read HEALTHY on the one state the
     * instrument is for" — so a raise is the only other total answer.
     */
    public function test_red_an_unseeded_cursor_clock_raises_rather_than_reading_as_caught_up(): void
    {
        $this->deliver($this->cleanTurn());

        DB::table('seat_state')->where('seat_ref', $this->seatRef)
            ->update(['fold_cursor_received_at' => null]);

        $this->advanceServerClock(61);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/fold_cursor_received_at is NULL/');

        SeatFacts::foldLagMs($this->state(), $this->nowMs());
    }

    /**
     * SECOND RED — THE INSTRUMENT WRITTEN BY THE THING IT MEASURES. "Make `fold_lag_ms` a STORED
     * COLUMN that the fold pass writes, and run the same fixture → the number FREEZES at whatever
     * the last pass wrote, no badge fires, `fold_current` never flips."
     *
     * The property that makes that impossible is asserted directly instead of mutating the schema:
     * with the fold NOT RUNNING, the lag must RISE between two reads. A stored lag — a number whose
     * only writer is the fold — cannot do that by construction, so this assertion fails under the
     * mutation and passes only for a quantity computed from a basis two other processes write.
     */
    public function test_second_red_the_lag_rises_while_the_fold_is_not_running(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->deliver($this->heartbeats(1));

        $this->advanceServerClock(30);
        $first = SeatFacts::foldLagMs($this->state(), $this->nowMs());

        $this->advanceServerClock(30);
        $second = SeatFacts::foldLagMs($this->state(), $this->nowMs());

        $this->assertGreaterThan(0, $first);
        $this->assertSame(30_000, $second - $first, 'the lag advanced with the clock, not with a pass');
    }

    /**
     * THIRD RED — THE WRONG OPERAND. "Compute the lag from the NEWEST unfolded event instead of the
     * cursor's own receipt time, and run the fixture against a seat that KEEPS RECEIVING → the lag
     * reads near zero throughout, because the newest unfolded event is always seconds old. The
     * storage and the operand are INDEPENDENT DEFECTS and this is the one that survives fixing the
     * other."
     *
     * Both operands are computed here on one fixture, and the assertion is that they DISAGREE by
     * more than the badge threshold. That is the whole content of the RED: a seat whose derivation
     * is minutes behind reads healthy under the wrong operand while it is still receiving.
     */
    public function test_third_red_the_newest_unfolded_event_reads_healthy_on_a_seat_that_keeps_receiving(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        // The fold stops here. The seat keeps sending for the next five minutes.
        for ($i = 0; $i < 5; $i++) {
            $this->advanceServerClock(60);
            $this->deliver($this->heartbeats(1));
        }

        $now = $this->nowMs();

        $correct = SeatFacts::foldLagMs($this->state(), $now);

        $newestUnfolded = DB::table('events')
            ->where('seat_ref', $this->seatRef)
            ->where('id', '>', (int) $this->state()->fold_cursor_event_id)
            ->orderByDesc('id')
            ->value('received_at');

        $wrongOperand = $now - Clock::toMs($newestUnfolded);

        $this->assertGreaterThan(Badges::FOLD_LAG_MS, $correct, 'the OLDEST unfolded event is minutes behind');
        $this->assertLessThan(Badges::FOLD_LAG_MS, $wrongOperand, 'the NEWEST unfolded event is always seconds old');
    }

    /**
     * The DISCRIMINATING CONTROL for the whole file: a caught-up seat that is receiving normally
     * badges nothing, so the instrument is known to be capable of reporting "the fold is fine".
     */
    public function test_a_caught_up_seat_never_badges_however_quiet_it_is(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->advanceServerClock(3600);
        $this->sweep();

        $this->assertSame(0, SeatFacts::foldLagMs($this->state(), $this->nowMs()));
        $this->assertNotContains('fold_lag', json_decode($this->state()->server_badges, true));
        $this->assertSame(0, $this->counter('fold_lag_alarm_entered'));

        // …and it is `offline` by now, which is § 4.5's answer to a quiet seat. The two instruments
        // are separate on purpose: "the transport went quiet" and "derivation went quiet" are
        // different failures and § 2.3 gives them the same THRESHOLDS so an operator comparing two
        // seats is comparing the same unit.
        $this->assertSame('offline', $this->state()->render_state);
    }

    private function sweep(): void
    {
        app(Sweep::class)->pass();
    }

    private function nowMs(): int
    {
        return Clock::toMs(Clock::sql(now()));
    }
}

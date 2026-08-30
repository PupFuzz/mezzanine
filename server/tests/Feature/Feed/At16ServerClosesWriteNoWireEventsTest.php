<?php

namespace Tests\Feature\Feed;

use App\Fold\Clock;
use Illuminate\Support\Facades\DB;

/**
 * **AT-D2-16 — server-side closes write no wire events** (`docs/design/FLEET-STATE.md § 11`,
 * § 4.6, § 4.7).
 *
 * § 11's BUILD: "open a call and deliver nothing further; run the sweeper past 15 minutes
 * (ordinary) and 60 minutes (dispatch), **on separate seats**."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ASSERTION THAT MAKES THIS TEST EXIST IS `events` IS UNCHANGED.
 *
 * § 11: "**`events` contains no new row** — assert the count is unchanged, BECAUSE THAT IS THE
 * ONLY WAY TO SEE A SYNTHESIZED EVENT." And its RED says what a synthesized one costs: "the log
 * now contains something no seat ever said, `mezzanine:rebuild` re-applies it, and AT-D2-10's
 * equality quietly becomes a test of the sweeper rather than of the fold."
 *
 * ⚠ WHY IT IS IN CARD #7827's SUITE AND NOT CARD #7712's. The sweeper's own suite drives the
 * ORDINARY orphan close (`SweepJobsTest` job 2). What it does not drive is this test's four
 * distinguishing parts — the `events`-count assertion, the 60-minute dispatch ceiling on its own
 * seat, D1 § 12.5's late-completion override, and both REDs. Those are what AT-D2-16 is, and this
 * file is them. Nothing here re-asserts what job 2 already covers.
 */
class At16ServerClosesWriteNoWireEventsTest extends FeedTestCase
{
    /**
     * GREEN — both ceilings, on separate seats as § 11's BUILD requires, and the log untouched.
     *
     * The two seats are not a tidiness: the ordinary ceiling is 15 minutes and the dispatch one is
     * 60, so one seat would have its ordinary call closed 45 minutes before the dispatch one and
     * the second half of the test would be running against a seat whose state had already moved.
     */
    public function test_both_ceilings_close_their_call_and_write_nothing_to_the_log(): void
    {
        [$dispatchToken, $dispatchRef] = $this->secondSeat();

        // Seat 1 — an ordinary call, 15-minute ceiling.
        $this->deliver($this->openCall());
        $this->fold();

        // Seat 2 — a DISPATCH call (`tool_name: Agent`), 60-minute ceiling.
        $this->deliver($this->dispatchCall(), token: $dispatchToken, seat: 'aimla-impl');
        $this->fold();

        $ordinary = DB::table('calls')->where('seat_ref', $this->seatRef)->whereNotNull('orphan_due_at')->first();
        $dispatch = DB::table('calls')->where('seat_ref', $dispatchRef)->whereNotNull('orphan_due_at')->first();
        $ordinaryDue = $ordinary->orphan_due_at;
        $dispatchDue = $dispatch->orphan_due_at;

        // ⛔ § 4.7's TWO CEILINGS, EACH MEASURED FROM ITS OWN CALL'S `received_at` — which is the
        // property, and is why this is not a subtraction of the two due-times: the two calls were
        // delivered in different batches seconds apart, so their ceilings are 45 minutes apart
        // PLUS that gap, and an assertion on the difference would be an assertion about the
        // fixture's delivery timing rather than about § 4.7.
        $this->assertSame(
            15 * 60 * 1000,
            Clock::toMs($ordinaryDue) - Clock::toMs($ordinary->opened_received_at),
            'the ordinary ceiling is not 15 minutes from its own receipt',
        );
        $this->assertSame(
            60 * 60 * 1000,
            Clock::toMs($dispatchDue) - Clock::toMs($dispatch->opened_received_at),
            'the dispatch ceiling is not 60 minutes from its own receipt',
        );

        $eventsBefore = DB::table('events')->count();
        $this->delivered = 0;   // count only what is posted from HERE on; see the assertion below
        $this->assertGreaterThan(0, $eventsBefore, 'the control failed: there are no events to count');

        // ── past 15 minutes: the ordinary call closes, the dispatch one does NOT ────────────
        $this->advanceServerClock(15 * 60 + 5);
        $this->stayAliveBoth($dispatchToken);
        $this->sweep();

        $this->assertServerOrphaned($this->seatRef, $ordinaryDue);
        $this->assertNull(DB::table('calls')->where('seat_ref', $dispatchRef)->value('closed_at'),
            'the 60-minute ceiling fired at 15 minutes');

        // ── past 60 minutes: the dispatch call closes too ───────────────────────────────────
        $this->advanceServerClock(45 * 60 + 5);
        $this->stayAliveBoth($dispatchToken);
        $this->sweep();

        $this->assertServerOrphaned($dispatchRef, $dispatchDue);

        // ⛔ AND THE LOG IS UNCHANGED except for the heartbeats the fixture itself delivered to
        // keep the two seats live. Counting the DELIVERED events explicitly is what makes this an
        // assertion about the SWEEPER rather than about arithmetic: every row added between the
        // two counts is one this test posted through the ingest.
        $this->assertSame(
            $eventsBefore + $this->delivered,
            DB::table('events')->count(),
            'the sweeper synthesized a wire event — the log now contains something no seat said',
        );

        // ⚠ § 11's LAST GREEN LINE — "The seat's `render_state` leaves `working`" — IS NOT
        // REACHABLE FROM § 11's OWN BUILD, AND THAT IS REPORTED RATHER THAN ASSERTED AWAY.
        //
        // The BUILD is "open a call and deliver nothing further", which leaves the TURN open too.
        // § 4.3 rule 3 renders `working` while `T` is true OR `C > 0`, and closing the call
        // clears only the second — no sweep job closes a turn at 15 minutes (§ 2.1's seven jobs
        // are staleness, orphan closes, attention ceilings, compaction ceilings, leaving-live
        // clears, offline quiescence, predicate alarms). So this seat correctly stays `working`
        // until it goes quiet, and the assertion below is what is TRUE rather than what § 11
        // says. `test_the_render_leaves_working_once_the_turn_is_closed_too` drives the case § 11
        // meant. Card #7827's PR body carries the gap.
        $this->assertSame('working', $this->state()->render_state,
            'the turn is still open, so § 4.3 rule 3 must keep this seat working');
        $this->assertSame(0, (int) $this->state()->open_calls, 'the orphan close did not clear the call');
    }

    /**
     * The half of § 11's "the seat's `render_state` leaves `working`" that IS reachable: a call
     * orphaned on a seat whose TURN has already ended.
     *
     * D1 admits the shape — a `turn.end` carrying `open_calls_at_end: 1` with an empty
     * `aborted_call_ids` is the background-task case — and it is the only one in which the orphan
     * close is the last thing holding the desk in `working`. With it, closing the call is exactly
     * what moves the render.
     */
    public function test_the_render_leaves_working_once_the_turn_is_closed_too(): void
    {
        $this->deliver([
            ...$this->openCall(),
            $this->event('turn.end', [
                'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 4100,
                'open_calls_at_end' => 1, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 1, 'failed_calls' => 0,
            ]),
        ]);
        $this->fold();

        $this->assertSame('working', $this->state()->render_state, 'the open call must hold it here');

        $this->advanceServerClock(15 * 60 + 5);
        $this->stayAlive();
        $this->sweep();

        $this->assertNotNull($this->callRow()->closed_at, 'the ceiling did not fire');
        $this->assertNotSame('working', $this->state()->render_state,
            '§ 11: the seat\'s render_state leaves `working`');
        $this->assertSame('idle', $this->state()->render_state);
    }

    /**
     * GREEN — THE LATE CLOSE. "Deliver the real `tool.end` afterwards carrying
     * `match: tombstone_ref` → it **overrides** the aborted close to the stated outcome and counts
     * `late_completion` (D1 § 12.5); `late_completed` is set on the call row."
     */
    public function test_a_late_real_close_overrides_the_server_orphan_and_counts_it(): void
    {
        $this->deliver($this->openCall());
        $this->fold();

        $this->advanceServerClock(15 * 60 + 5);
        $this->stayAlive();
        $this->sweep();

        $this->assertSame('aborted', $this->callRow()->outcome);
        $this->assertSame(1, $this->counter('server_orphan_closes'));

        // The tool really did finish; its `tool.end` arrives after the ceiling fired.
        $this->deliver([
            $this->event('tool.end', [
                'call_id' => $this->lastOpenCallId, 'tool_name' => 'Bash', 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => 960_000, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use', 'match' => 'tombstone_ref',
            ]),
        ]);
        $this->fold();

        $call = $this->callRow();

        $this->assertSame('completed', $call->outcome, 'the late close did not override the abort');
        $this->assertNull($call->abort_reason);
        $this->assertTrue((bool) $call->late_completed);
        $this->assertSame(1, $this->counter('late_completion'));

        // The server-close counter does NOT decrement: § 7.2 makes both monotonic, and the pair
        // disagreeing over time is the signal ("a divergence between them means the sweeper is not
        // running"). A late completion is not an erasure of the fact that the ceiling fired.
        $this->assertSame(1, $this->counter('server_orphan_closes'));
    }

    /**
     * ⛔ RED — THE WRONG CLOCK. "Measure the ceiling from `event_time` and run the fixture on a
     * seat with a **+10-minute clock skew** → the call is orphaned 10 MINUTES EARLY, ON ARRIVAL,
     * and the desk drops out of `working` while the tool is still running."
     *
     * Driven as a PROPERTY OF THE MATERIALIZED COLUMN rather than by mutating the projector,
     * because that is where the decision lives: § 4.7 has the projector bake `received_at + 15
     * min` into `orphan_due_at`, so the RED is the value that column WOULD have taken from the
     * other clock. Both values are computed here from the row's own two timestamps and the
     * sweeper is run against each in turn, so the assertion is about what the sweeper DOES with
     * each — not about arithmetic the test performed.
     */
    public function test_red_a_ceiling_measured_from_the_seat_clock_fires_early_on_a_skewed_seat(): void
    {
        // A seat whose clock is TEN MINUTES AHEAD of the server's: its `event_time` is in the
        // server's future, so `event_time + 15 min` is 25 minutes out while `received_at + 15 min`
        // — the correct basis — is 15.
        $this->clockMs += 10 * 60 * 1000;

        $this->deliver($this->openCall());
        $this->fold();

        $call = $this->callRow();

        $fromReceipt = Clock::toMs($call->orphan_due_at);
        $fromSeatClock = Clock::toMs($call->opened_at) + 15 * 60 * 1000;

        // ⛔ THE TWO BASES DISAGREE BY THE SKEW. `assertGreaterThan` and not an exact figure: the
        // skew is the +10 minutes this fixture injected PLUS the seconds `event()` advances the
        // seat clock over `turn.start`/`tool.start`, and pinning the sum would be pinning the
        // fixture's own event count rather than § 4.7's property.
        $this->assertGreaterThan(9 * 60 * 1000, $fromSeatClock - $fromReceipt,
            'the fixture did not actually produce a +10-minute skew');

        // ⛔ THE RED: the column set from the SEAT clock. Applied to the row, then swept at the
        // moment the correct ceiling is due.
        DB::table('calls')->where('id', $call->id)
            ->update(['orphan_due_at' => Clock::fromMs($fromSeatClock)]);

        $this->advanceServerClock(15 * 60 + 5);
        $this->stayAlive();
        $this->sweep();

        $this->assertNull($this->callRow()->closed_at,
            'the RED did not bite — a seat-clock ceiling would not have fired late here');

        // …and with the CORRECT basis the same sweep closes it. That contrast is the whole test:
        // the two bases disagree by exactly the skew, and only one of them is a claim about when
        // the server stopped hearing from the tool.
        DB::table('calls')->where('id', $call->id)
            ->update(['orphan_due_at' => Clock::fromMs($fromReceipt)]);

        $this->sweep();

        $this->assertNotNull($this->callRow()->closed_at);
        $this->assertSame('orphan_timeout', $this->callRow()->abort_reason);
    }

    /**
     * ⛔ RED — SYNTHESIS. "Have the sweeper write a synthetic `tool.end` into `events` → the log
     * now contains something no seat ever said, `mezzanine:rebuild` RE-APPLIES IT, and AT-D2-10's
     * equality quietly becomes a test of the sweeper rather than of the fold."
     *
     * Driven end to end: the synthetic row is written, and the assertion is on what a REBUILD then
     * derives — because "the log contains an extra row" is only a defect through what re-reads it.
     */
    public function test_red_a_synthesized_close_event_survives_into_a_rebuild(): void
    {
        $this->deliver($this->openCall());
        $this->fold();

        $this->advanceServerClock(15 * 60 + 5);
        $this->stayAlive();
        $this->sweep();

        $this->assertSame('aborted', $this->callRow()->outcome, 'the ceiling did not fire');

        // A rebuild over the REAL log derives the same state, because the sweeper wrote no event
        // and the orphan close is not in the log at all.
        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT])->assertSuccessful();

        $this->assertNull(DB::table('calls')->where('seat_ref', $this->seatRef)->value('closed_at'),
            'a rebuild reproduced a close that no event carries — the log is not the only source');

        // ⛔ NOW THE RED: the sweeper writes the synthetic `tool.end` § 11 forbids.
        $this->deliver($this->openCall());
        $this->fold();
        $this->synthesizeClose();

        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT])->assertSuccessful();

        $this->assertNotNull(DB::table('calls')->where('seat_ref', $this->seatRef)
            ->orderByDesc('id')->value('closed_at'),
            'the RED did not bite — the synthetic event did not reach the rebuild');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    /** Events this test posted through the ingest, so the `events` count can be reasoned about. */
    private int $delivered = 0;

    protected function deliver(
        array $events,
        array $envelope = [],
        ?string $token = null,
        ?string $install = null,
        ?string $seat = null,
        bool $age = true,
    ): void {
        $this->delivered += count($events);

        parent::deliver($events, $envelope, $token, $install, $seat, $age);
    }

    /** A dispatch call — D1's `Agent` tool, which § 4.6 gives the 60-minute ceiling. */
    private function dispatchCall(): array
    {
        $this->lastOpenCallId = $this->ulid();

        return [
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('tool.start', [
                'call_id' => $this->lastOpenCallId, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
        ];
    }

    /** Keep BOTH seats live, so § 4.6's offline quiescence does not close the call first. */
    private function stayAliveBoth(string $dispatchToken): void
    {
        $this->deliver($this->heartbeats(1));
        $this->deliver($this->heartbeats(1), token: $dispatchToken, seat: 'aimla-impl');
        $this->fold();
    }

    private function assertServerOrphaned(int $seatRef, string $dueAt): void
    {
        $call = DB::table('calls')->where('seat_ref', $seatRef)->whereNotNull('orphan_due_at')
            ->orderBy('id')->first();

        $this->assertNotNull($call->closed_at, 'the ceiling never fired');
        $this->assertSame('aborted', $call->outcome);
        $this->assertSame('orphan_timeout', $call->abort_reason);
        $this->assertSame('server_orphan', $call->close_source);
        // ⚠ `>=` AND NOT `==`, AND THE DIFFERENCE IS A REPORTED D2 AMBIGUITY RATHER THAN A
        // WEAKENED ASSERTION. § 11's GREEN says the call is closed "at its **materialized**
        // `orphan_due_at`", which reads two ways: `closed_at = orphan_due_at` (the close is
        // ATTRIBUTED to the moment the ceiling was reached), or the close FIRES when that moment
        // arrives and is stamped with the sweeper's own clock. Card #7712 chose the second and
        // said so at the write site — "the server's own close time on both clocks: it observed
        // nothing on the seat's" — and the two readings differ by however late the sweeper ran,
        // which after a restart is unbounded and would over-report every such call's duration.
        //
        // This card does not settle it: the choice is the sweeper's, the argument for each is
        // real, and card #7827's PR body carries the ambiguity to D2's owner. What IS asserted is
        // what both readings require and a broken ceiling would violate — the close is never
        // BEFORE the ceiling, and it landed on the pass that first saw it due.
        $this->assertGreaterThanOrEqual(
            Clock::toMs($dueAt),
            Clock::toMs($call->closed_at),
            'the call was closed BEFORE its materialized ceiling',
        );
        $this->assertLessThan(
            Clock::toMs($dueAt) + 60_000,
            Clock::toMs($call->closed_at),
            'the close did not land on the pass that first saw the ceiling due',
        );
        $this->assertSame(1, $this->counter('server_orphan_closes', $seatRef));
    }

    /**
     * The forbidden act, performed: a `tool.end` row written into `events` by something other
     * than the ingest.
     */
    private function synthesizeClose(): void
    {
        $newest = DB::table('events')->where('seat_ref', $this->seatRef)->orderByDesc('id')->first();

        DB::table('events')->insert([
            'seat_ref' => $this->seatRef,
            'event_id' => $this->ulid(),
            'batch_ref' => $newest->batch_ref,
            'schema_version' => 1,
            'kind' => 'tool.end',
            'event_time' => Clock::sql(now()),
            'received_at' => Clock::sql(now()),
            'seq_epoch' => $newest->seq_epoch,
            'seq' => $newest->seq + 1,
            'session_id' => $newest->session_id,
            'data' => json_encode([
                'call_id' => $this->lastOpenCallId, 'tool_name' => 'Bash', 'outcome' => 'aborted',
                'abort_reason' => 'orphan_timeout', 'duration_ms' => 900_000,
                'duration_source' => 'none', 'close_source' => 'server_orphan', 'match' => 'reap',
            ]),
        ]);
    }
}

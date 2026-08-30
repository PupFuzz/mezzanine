<?php

namespace Tests\Feature\Sweep;

use App\Fold\Clock;
use App\Sweep\PlaneClock;
use App\Sweep\Predicates;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 2.1`'s SEVEN time-derived jobs, one section each.
 *
 * Every test here is written so that it FAILS IF ITS JOB IS REMOVED — that is the card's
 * load-bearing requirement and the reason each one asserts a write the fold provably cannot make.
 * The fold's claim predicate is `fold_cursor_event_id < head_event_id` (§ 6.5), so a seat with no
 * unfolded events is never visited by it at all; every state below is reached by the passage of
 * TIME on a seat that has stopped producing events, which is exactly the population § 2.2 says only
 * a dead sweep can strand ("a dead sweep freezes time-driven ones, and only the second one can
 * leave a dead seat rendering `working`").
 */
class SweepJobsTest extends SweepTestCase
{
    // ── JOB 1: staleness (§ 4.5) ─────────────────────────────────────────────────────────────

    /**
     * The transition a permanently quiet desk exists to produce, and the one no fold pass can make.
     *
     * § 6.5 names the sweeper explicitly among the three version-bearing writers "because its
     * `live → stale` transition is the ONLY DELTA A PERMANENTLY QUIET DESK WILL EVER GET".
     */
    public function test_job_1_a_seat_that_stopped_reporting_reaches_stale_with_no_further_events(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame('idle', $this->state()->render_state);
        $before = (int) $this->state()->state_version;

        // 400 s of silence, and NOT ONE unfolded event: the fold is running (it is run below and
        // does nothing), the seat is simply not sending. Without the sweep pass the desk stays
        // `idle` for ever — this seat is never claimed.
        $this->advanceServerClock(400);
        $this->fold();
        $this->assertSame('idle', $this->state()->render_state, 'the FOLD cannot make this transition');

        $this->sweep();

        $state = $this->state();
        $this->assertSame('stale', $state->render_state);
        $this->assertSame('stale', $state->link_state);

        // MASKED, NOT CLEARED (§ 4.4): idle is a claim about something that already happened, and
        // staleness does not falsify it.
        $this->assertSame('idle', $state->activity_state);

        // § 6.5's per-writer rule: a pass that moves a version-bearing field bumps `state_version`
        // and enqueues its delta like any other writer.
        $this->assertGreaterThan($before, (int) $state->state_version);
        $this->assertContains('staleness_sweep', $this->causes());

        $row = collect($this->transitions())->last();
        $this->assertSame('idle', $row['from']);
        $this->assertSame('stale', $row['to']);
    }

    public function test_job_1_a_seat_receiving_normally_is_not_marked_by_the_pass(): void
    {
        // The discriminating control § 5 asks for by name: "a fixture seat receiving normally must
        // not [flip]", or the sweep is simply marking everything.
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->sweep();

        $this->assertSame('idle', $this->state()->render_state);
        $this->assertSame('live', $this->state()->link_state);
        $this->assertNotContains('staleness_sweep', $this->causes());
    }

    public function test_job_1_every_pass_stamps_sweep_last_run_at(): void
    {
        // § 8.2.4: "`null` before the sweeper's first pass" — the ABSENCE of the row is that null,
        // so nothing has to remember which sentinel value means "never".
        $this->assertNull(PlaneClock::lastRunAt(PlaneClock::SWEEP));
        $this->assertSame('stalled', PlaneClock::sweepHealth($this->nowMs()));

        $this->sweep();

        $this->assertNotNull(PlaneClock::lastRunAt(PlaneClock::SWEEP));
        $this->assertSame('ok', PlaneClock::sweepHealth($this->nowMs()));

        // § 2.2's dead-sweep rule, with § 8.2.4's threshold: `stalled` past 60 s since the stamp.
        $this->advanceServerClock(61);
        $this->assertSame('stalled', PlaneClock::sweepHealth($this->nowMs()));
    }

    // ── JOB 2: orphan-timeout closes (§ 4.6, § 4.7) ──────────────────────────────────────────

    public function test_job_2_an_open_call_is_closed_at_its_materialized_orphan_due_at(): void
    {
        $this->deliver($this->openCall());
        $this->fold();

        $this->assertSame('working', $this->state()->render_state);
        $this->assertNull($this->callRow()->closed_at);

        // 16 minutes — past the ordinary 15-minute ceiling (D1 § 12.5). The seat keeps heartbeating
        // so this test drives job 2 and not job 6; the interaction between them has its own case.
        $this->advanceServerClock(16 * 60);
        $this->stayAlive();

        $this->assertNull($this->callRow()->closed_at, 'the FOLD does not close an orphan');

        $this->sweep();

        $call = $this->callRow();
        $this->assertNotNull($call->closed_at);
        $this->assertSame('aborted', $call->outcome);
        $this->assertSame('orphan_timeout', $call->abort_reason);
        $this->assertSame('server_orphan', $call->close_source);

        // TWO COUNTERS, one close (§ 7.2): D1's ledger rule and this sweeper's execution of it,
        // "counted separately because a divergence between them means the sweeper is not running".
        $this->assertSame(1, $this->counter('server_orphan_closes'));
        $this->assertSame(1, $this->counter('orphan_timeout_closes'));

        $this->assertContains('orphan_timeout', $this->causes());
    }

    public function test_job_2_a_dispatch_call_gets_sixty_minutes_and_an_ordinary_one_does_not(): void
    {
        // § 4.6's two ceilings, and the discriminating control between them: the SAME elapsed time
        // must close one and not the other, or the test is measuring the sweep pass rather than the
        // materialized due time.
        $this->deliver($this->openCall('Agent'));
        $this->fold();

        $this->advanceServerClock(16 * 60);
        $this->stayAlive();
        $this->sweep();

        $this->assertNull($this->callRow()->closed_at, 'a dispatch call has 60 minutes, not 15');
        $this->assertSame(0, $this->counter('server_orphan_closes'));

        $this->advanceServerClock(45 * 60);
        $this->stayAlive();
        $this->sweep();

        $this->assertSame('orphan_timeout', $this->callRow()->abort_reason);
    }

    // ── JOB 3: attention ceilings (§ 4.4) ────────────────────────────────────────────────────

    public function test_job_3_an_open_attention_request_is_resolved_at_its_sixty_minute_ceiling(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->assertSame('blocked', $this->state()->render_state);
        $ceiling = $this->requestRow()->ceiling_at;

        $this->advanceServerClock(61 * 60);
        $this->stayAlive();

        $this->assertNull($this->requestRow()->resolved_at, 'the FOLD does not fire a ceiling');

        $this->sweep();

        $request = $this->requestRow();
        $this->assertSame('server_ceiling', $request->resolution);
        $this->assertSame('server_ceiling', $request->resolution_source);

        // RESOLVED AT THE CEILING, not at the pass time: `D2-MUST` #5's bound is the ceiling, and
        // stamping `now` would put up to one sweep cadence of the server's own scheduling into
        // `waited_ms` and give one physical event two answers depending on sweeper load.
        $this->assertSame($ceiling, $request->resolved_at);
        $this->assertSame(3_600_000, (int) $request->waited_ms);

        $this->assertSame(1, $this->counter('attention_ceiling_expired'));

        // The cause value exists "so the drill-down can say THE SERVER CLEARED THIS, which is
        // exactly the distinction a `staleness_sweep` or a `wire_event` cause would lose" (§ 4.4).
        $this->assertContains('attention_ceiling', $this->causes());
        $this->assertNotSame('blocked', $this->state()->render_state);
    }

    public function test_job_3_a_late_attention_resolved_overrides_the_ceiling_and_never_reopens_blocked(): void
    {
        // D1 § 12.5's rule applied to the state D1 hands D2: "an observation overrides an
        // inference". This is the sweeper's half of § 4.4's `attention_ceiling_overridden`, which
        // had no producer before this card because nothing fired the ceiling.
        $events = $this->blockedPair(requestOnly: true);
        $requestId = $events[2]['data']['request_id'];

        $this->deliver($events);
        $this->fold();

        $this->advanceServerClock(61 * 60);
        $this->stayAlive();
        $this->sweep();

        $this->deliver([$this->event('attention.resolved', [
            'request_id' => $requestId, 'resolution' => 'granted',
            'resolution_source' => 'call_close', 'waited_ms' => 3_601_000,
        ])]);
        $this->fold();

        $this->assertSame('granted', $this->requestRow()->resolution);
        $this->assertSame(1, $this->counter('attention_ceiling_overridden'));
        $this->assertNotSame('blocked', $this->state()->render_state);
    }

    // ── JOB 4: compaction ceilings (§ 4.6) ───────────────────────────────────────────────────

    public function test_job_4_an_open_compaction_is_closed_fifteen_minutes_after_its_receipt(): void
    {
        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('compaction.start', ['trigger' => 'auto', 'context_used_pct' => 92.5]),
        ]);
        $this->fold();

        $this->assertNotNull($this->sessionRow()->compaction_open_since);

        $this->advanceServerClock(16 * 60);
        $this->stayAlive();

        $this->assertNotNull($this->sessionRow()->compaction_open_since, 'the FOLD does not fire a ceiling');

        $this->sweep();

        $this->assertNull($this->sessionRow()->compaction_open_since);
        $this->assertNull($this->sessionRow()->compaction_open_received_at);
        $this->assertSame(1, $this->counter('compaction_ceiling_closed'));
    }

    public function test_job_4_closing_a_compaction_mints_no_state_and_no_transition_row(): void
    {
        // § 4.8: "§ 4.3 reads no compaction fact", so nothing version-bearing moves and nothing is
        // announced. The counter is the whole of the signal — "rising ⇒ `compaction.end` is not
        // arriving; `PostCompact` is one of D1's un-driven hook stubs" (§ 7.2).
        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('compaction.start', ['trigger' => 'auto', 'context_used_pct' => 92.5]),
        ]);
        $this->fold();

        $this->advanceServerClock(16 * 60);
        $this->stayAlive();

        $before = $this->transitions();
        $this->sweep();

        $this->assertSame($before, $this->transitions());
        $this->assertSame('working', $this->state()->render_state);   // the open turn, not the compaction
    }

    // ── JOB 5: the leaving-live clears (§ 4.5) ───────────────────────────────────────────────

    public function test_job_5_a_stalled_flag_is_cleared_when_the_seat_leaves_live(): void
    {
        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('turn.end', [
                'end_reason' => 'api_error', 'api_error_type' => 'rate_limit', 'duration_ms' => 800,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 0, 'failed_calls' => 0,
            ]),
        ]);
        $this->fold();

        $this->assertSame('stalled', $this->state()->render_state);

        $this->advanceServerClock(400);
        $this->fold();
        $this->assertNotNull($this->sessionRow()->stalled_since, 'the FOLD does not clear a stall on silence');

        $this->sweep();

        $session = $this->sessionRow();

        // `stalled_since` IS NULLED here and only STAMPED on a `session.end`, and the asymmetry is
        // § 4.3's `S` = (stalled_since set AND ended_at null): a seat going quiet ends no session,
        // so the second term is unavailable and the first is the only one that can go false.
        $this->assertNull($session->stalled_since);
        $this->assertSame('left_live', $session->stalled_cleared_by);
        $this->assertSame(1, $this->counter('left_live_cleared_stalls'));

        $state = $this->state();
        $this->assertSame('stale', $state->render_state);
        $this->assertSame('unknown', $state->activity_state);
        $this->assertSame('stalled_left_live', $state->unknown_reason);
    }

    public function test_job_5_an_open_attention_request_is_resolved_when_the_seat_leaves_live(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->assertSame('blocked', $this->state()->render_state);

        $this->advanceServerClock(400);
        $this->sweep();

        $request = $this->requestRow();
        $this->assertSame('seat_left_live', $request->resolution);
        $this->assertSame('server_left_live', $request->resolution_source);
        $this->assertSame(1, $this->counter('left_live_resolved_attention'));

        // § 4.4: "a seat returning at 400 s must not re-render a wait whose evidence is five minutes
        // stale" — discharged by CLEARING THE FACT rather than by masking it, which is why this is
        // asserted on `activity_state` and not only on the render.
        $this->assertNotSame('blocked', $this->state()->activity_state);
    }

    public function test_job_5_idle_is_deliberately_not_in_this_rule(): void
    {
        // § 4.4 / § 4.5: `blocked` and `stalled` are claims the seat is CURRENTLY waiting or
        // currently refused; `idle` is a claim about something that ALREADY HAPPENED, "which
        // staleness does not falsify". So leaving `live` MASKS idle through § 4.2 and does not
        // clear it — the discriminating control for the two cases above.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->advanceServerClock(400);
        $this->sweep();

        $this->assertSame('stale', $this->state()->render_state);
        $this->assertSame('idle', $this->state()->activity_state);
    }

    public function test_job_5_never_overwrites_a_clearer_already_recorded(): void
    {
        // § 4.5's second condition, which "excludes a real write": a session cleared by
        // `session_end` earlier that day must keep that clearer, because `stalled_cleared_by` is an
        // input to § 4.3's reason table and the overwrite would silently change the reason a later
        // derivation reports for a turn that ended long before the seat went quiet.
        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 40]),
            $this->event('turn.end', [
                'end_reason' => 'api_error', 'api_error_type' => 'rate_limit', 'duration_ms' => 800,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 0, 'failed_calls' => 0,
            ]),
            $this->event('session.end', [
                'end_reason' => 'logout', 'duration_ms' => 9000, 'turns' => 1, 'aborted_calls' => 0,
            ]),
        ]);
        $this->fold();

        $this->assertSame('session_end', $this->sessionRow()->stalled_cleared_by);

        $this->advanceServerClock(400);
        $this->sweep();

        $this->assertSame('session_end', $this->sessionRow()->stalled_cleared_by);
        $this->assertSame(0, $this->counter('left_live_cleared_stalls'));
        $this->assertSame('stalled_session_ended', $this->state()->unknown_reason);
    }

    // ── JOB 6: offline quiescence (§ 4.6) ────────────────────────────────────────────────────

    public function test_job_6_an_offline_seat_has_its_open_facts_closed(): void
    {
        // A DISPATCH call, so its own 60-minute ceiling is not due at 900 s and job 6 is the writer
        // under test rather than job 2. § 6.4 keeps `server_offline` on `calls.close_source` and
        // `sessions.closed_by` for exactly this reason: "a 60-min dispatch call is still open at
        // 900 s".
        $this->deliver($this->openCall('Agent'));
        $this->fold();

        $this->advanceServerClock(1000);
        $this->fold();
        $this->assertNull($this->callRow()->closed_at, 'the FOLD does not quiesce');

        $this->sweep();

        $call = $this->callRow();
        $this->assertSame('aborted', $call->outcome);
        $this->assertSame('seat_offline', $call->abort_reason);
        $this->assertSame('server_offline', $call->close_source);

        $session = $this->sessionRow();
        $this->assertNotNull($session->ended_at);
        $this->assertSame('server_offline', $session->closed_by);
        $this->assertFalse((bool) $session->turn_open);
        $this->assertSame('server_offline', $session->turn_close_source);
        $this->assertSame('server_session_close', $session->last_turn_end_reason);

        // ZERO, and § 4.6 says why: "the calls were closed by the step before this one and counted
        // there, so this turn close aborts none of its own". The alternative is one call closed
        // twice and a drill-down left to guess which close was the real one.
        $this->assertSame(0, (int) $session->last_turn_aborted_count);
        $this->assertSame(0, $this->counter('session_close_orphans'));

        $this->assertSame(1, $this->counter('offline_quiesced_calls'));
        $this->assertSame(1, $this->counter('offline_quiesced_sessions'));

        $state = $this->state();
        $this->assertSame('offline', $state->render_state);
        $this->assertSame('unknown', $state->activity_state);
        $this->assertSame('session_closed_turn_open', $state->unknown_reason);
        $this->assertContains('offline_quiesce', $this->causes());

        // ⛔ AND EXACTLY ONE ROW FOR THE ONE PHYSICAL EVENT — added by card #7837, and it is not
        // padding on the `assertContains` above: that assertion is satisfied by a pass that writes
        // the `offline_quiesce` row AND a second `staleness_sweep` row for the same
        // `working → offline` change, which is § 4.6's "one physical event, one set of values, one
        // counter" broken in a way the drill-down cannot repair — two rows, two causes, and a
        // reader left to guess which one is the real diagnosis.
        //
        // ⚠ SEEN TO FAIL, AND THE LEVER IS THE ONE CHANGE A LATER CARD IS MOST LIKELY TO MAKE.
        // `Sweep::seat()` samples JOB 1's version-bearing fingerprint at its own call site, and
        // that call site's comment records the one instance of card #7837's class left open —
        // JOB 5 (`leavingLive`) settles through JOB 1, so its writes land above the sample. Moving
        // the sample above JOB 2 is the obvious fix and it is WRONG: measured, that produces
        //
        //   [{"from":"working","to":"offline","cause":"offline_quiesce"},
        //    {"from":"working","to":"offline","cause":"staleness_sweep"}]
        //
        // where this fixture must produce only the first. Every one of this suite's 68 sweeper
        // tests passed under that mutation before this assertion existed, which is why it exists.
        $quiesceRows = array_values(array_filter(
            $this->transitions(),
            fn ($r) => $r['to'] === 'offline',
        ));

        $this->assertSame(
            [['from' => 'working', 'to' => 'offline', 'cause' => 'offline_quiesce']],
            $quiesceRows,
            'one physical event produced two transition rows (§ 4.6)',
        );
    }

    /**
     * ⚠ THIS TEST MEASURES ONE WRITE-SITE PER FACT, NOT AN EXECUTION ORDER, AND THE DISTINCTION WAS
     * ESTABLISHED BY WATCHING IT FAIL TO FAIL.
     *
     * An earlier revision of this case was titled for § 4.6's ordering, and a mutation that SWAPPED
     * jobs 5 and 6 left every assertion below green — so the test never measured the order and the
     * title was a claim the assertions did not support. That is the right outcome and the wrong
     * name: quiescence writes a set of facts DISJOINT from the leaving-live clear's, so the four
     * ENUM members § 6.4 deleted (`stalled_cleared_by: server_offline`, `resolution: seat_offline`,
     * `resolution_source: server_offline`) have no writer whatever the schedule. What IS asserted
     * here is § 4.6's actual requirement — "ONE QUIET SEAT, ONE WRITE-SITE, ON THE EARLIER EDGE" —
     * on the hardest path for it: a seat silent for more than 900 s between two passes takes
     * `offline` DIRECTLY and never has a pass in which § 4.5 rule 3 matched, so both facts must
     * still be cleared, once, in the LEAVING-LIVE vocabulary.
     *
     * The mutation that reds it is a SECOND WRITE-SITE — quiescence resolving the request itself —
     * which is exactly the alternative § 4.6 refuses: "two sweeper jobs racing to record different
     * clearers for it, which § 4.3's reason table would then read as two different diagnoses."
     */
    public function test_job_6_quiescence_adds_no_second_write_site_for_a_seat_going_quiet(): void
    {
        $events = $this->blockedPair(requestOnly: true);
        $events[] = $this->event('turn.end', [
            'end_reason' => 'api_error', 'api_error_type' => 'rate_limit', 'duration_ms' => 800,
            'open_calls_at_end' => 1, 'aborted_call_ids' => [], 'stop_hook_active' => false,
            'background_tasks_open' => 0, 'tool_calls' => 1, 'failed_calls' => 0,
        ]);

        $this->deliver($events);
        $this->fold();

        $this->assertNotNull($this->sessionRow()->stalled_since);
        $this->assertNull($this->requestRow()->resolved_at);

        // ONE jump, straight past both thresholds, and ONE pass.
        $this->advanceServerClock(1000);
        $this->sweep();

        $this->assertSame('left_live', $this->sessionRow()->stalled_cleared_by);
        $this->assertSame('seat_left_live', $this->requestRow()->resolution);
        $this->assertSame('server_left_live', $this->requestRow()->resolution_source);
        $this->assertSame(1, $this->counter('left_live_cleared_stalls'));
        $this->assertSame(1, $this->counter('left_live_resolved_attention'));

        // § 7.2: "There is no `offline_quiesced_attention` twin: an open attention request is
        // resolved by the leaving-live clear before quiescence sees it."
        $this->assertSame(0, $this->counter('offline_quiesced_attention'));
    }

    public function test_job_6_a_quiet_offline_seat_does_not_write_a_row_on_every_pass(): void
    {
        // The level-triggered job's own control. § 2.1 runs 5,760 passes a seat-day; a job that
        // owed a transition row unconditionally would write 5,760 rows and 5,760 deltas for a desk
        // that is doing nothing, which is the noise § 6.5 keeps out of `seat_state_transitions` by
        // sizing it from the RENDER-change rate rather than the delta rate.
        $this->deliver($this->openCall('Agent'));
        $this->fold();

        $this->advanceServerClock(1000);
        $this->sweep();

        $after = $this->transitions();
        $version = (int) $this->state()->state_version;

        $this->advanceServerClock(15);
        $this->sweep();
        $this->advanceServerClock(15);
        $this->sweep();

        $this->assertSame($after, $this->transitions());
        $this->assertSame($version, (int) $this->state()->state_version);
    }

    // ── JOB 7: the predicate-constant alarms (§ 5) ───────────────────────────────────────────

    public function test_job_7_every_per_pass_predicate_records_a_branch_on_every_pass(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        // § 5 rule 2: "Every predicate reports BOTH BRANCH COUNTS into `seat_predicates`, ON EVERY
        // EVALUATION." Nothing is recorded before the sweeper runs, which is the whole of job 7's
        // fail-without-it: the alarm pass would have an empty population.
        $this->assertNull($this->predicate('seat_live'));

        $this->sweep();

        $this->assertSame(1, (int) $this->predicate('seat_live')->true_count);
        $this->assertSame(1, (int) $this->predicate('activity_recent')->true_count);
        $this->assertSame(1, (int) $this->predicate('fold_current')->true_count);
        $this->assertSame(
            1,
            (int) $this->predicate('ingest_receiving', Predicates::FLEET)->true_count,
            'the fleet-wide predicate takes § 6.4\'s reserved seat_ref 0 sentinel',
        );
    }

    public function test_job_7_seat_live_and_activity_recent_are_the_discriminating_pair(): void
    {
        // § 5, on `activity_recent`'s control row: "IF THESE TWO PREDICATES EVER MOVE TOGETHER,
        // ACTIVITY IS BEING WRITTEN FROM RECEIPT — that is the discriminating pair, and it is the
        // mechanised form of § 3." A heartbeat-only seat is the fixture that separates them: its
        // receipt age stays near zero while its activity age grows without bound.
        $this->deliver($this->heartbeats(20));
        $this->fold();
        $this->advanceServerClock(950);
        $this->deliver($this->heartbeats(1));
        $this->fold();

        $this->sweep();

        $this->assertSame(1, (int) $this->predicate('seat_live')->true_count, 'the pipe is alive');
        $this->assertSame(0, (int) $this->predicate('seat_live')->false_count);
        $this->assertSame(0, (int) $this->predicate('activity_recent')->true_count);
        $this->assertSame(1, (int) $this->predicate('activity_recent')->false_count, 'the agent is not');
    }

    public function test_job_7_the_consecutive_criterion_alarms_and_clears(): void
    {
        // `fold_current`'s criterion, § 5: "constant-`false` for 2 consecutive passes alarms", with
        // the control "pause the fold daemon → `false` within one pass; resume → `true`".
        $this->deliver($this->cleanTurn(), age: false);

        // ONE pass is not two: the criterion is about consecutiveness and a first `false` has no
        // prior branch to be consecutive with.
        $this->advanceServerClock(120);
        $this->sweep();
        $this->assertSame(1, (int) $this->predicate('fold_current')->false_count);
        $this->assertNull($this->predicate('fold_current')->alarm_since);

        $this->advanceServerClock(15);
        $this->sweep();
        $this->assertNotNull($this->predicate('fold_current')->alarm_since, 'two consecutive falses');

        // Resume the fold → the seat catches up, the lag is pinned to 0 by the cursor test, and the
        // alarm CLEARS. An alarm that cannot clear is one that gets trained away.
        $this->fold();
        $this->advanceServerClock(15);
        $this->sweep();

        $this->assertNull($this->predicate('fold_current')->alarm_since);
        $this->assertGreaterThan(0, (int) $this->predicate('fold_current')->true_count);
    }

    public function test_job_7_the_alarm_pass_covers_predicates_this_process_does_not_evaluate(): void
    {
        // `attention_resolved_by_wire` is evaluated by the FOLD (a wire resolution) and by the
        // SWEEPER (a ceiling). Its criterion — "ANY server-ceiling resolution in 24 h is surfaced" —
        // is job 7's to apply, and job 7 iterates the TABLE rather than a list of names it owns, so
        // a predicate recorded elsewhere is still alarmed on.
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->advanceServerClock(61 * 60);
        $this->stayAlive();
        $this->sweep();

        $this->assertSame(1, (int) $this->predicate('attention_resolved_by_wire')->false_count);
        $this->assertNotNull($this->predicate('attention_resolved_by_wire')->alarm_since);
    }

    public function test_job_7_turn_clean_records_both_branches_from_the_fold(): void
    {
        // § 5's control for `turn_clean`: "AT-D2-2's `/clear` fixture drives `false`; AT-D2-1's
        // ordinary turn drives `true`." Both branches from real fixtures, which is what makes the
        // predicate evidence rather than decoration — "constant-`true` means the abort path is not
        // reaching the derivation, the false-idle defect returning".
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->assertSame(1, (int) $this->predicate('turn_clean')->true_count);

        $this->deliver($this->clearKill());
        $this->fold();
        $this->assertSame(1, (int) $this->predicate('turn_clean')->false_count);

        $this->sweep();

        // ⚠ THIS LINE NO LONGER MEANS WHAT IT USED TO SAY, AND THE COMMENT IS CORRECTED RATHER THAN
        // THE ASSERTION REMOVED. It used to read "a mixed distribution does not alarm" — but
        // `turn_clean` is a windowed criterion and § 6.4 carries no windowed count, so it now
        // reports `cannot_evaluate` on EVERY distribution and can never set `alarm_since` at all.
        // The null here is therefore evidence that the refusal writes nothing, not evidence that
        // the criterion discriminated. `PredicateAlarmsTest` owns the discrimination.
        $this->assertNull($this->predicate('turn_clean')->alarm_since, 'a refusal writes no verdict');
    }

    public function test_job_7_call_closed_by_wire_separates_the_wire_from_the_server(): void
    {
        // § 5: the two branches are "a call closed by a `tool.end`" / "by a server orphan or
        // quiescence", and "this is the server-side twin of D1's `late_completion` signal".
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame(1, (int) $this->predicate('call_closed_by_wire')->true_count);
        $this->assertSame(0, (int) $this->predicate('call_closed_by_wire')->false_count);

        $this->deliver($this->openCall());
        $this->fold();
        $this->advanceServerClock(16 * 60);
        $this->stayAlive();
        $this->sweep();

        $this->assertSame(1, (int) $this->predicate('call_closed_by_wire')->false_count);
    }

    public function test_job_7_ingest_receiving_separates_a_dead_pipe_from_dead_seats(): void
    {
        // § 5: "This is the predicate that separates 'every seat died' from 'our pipe is broken',
        // and without it a fleet-wide ingest outage renders as 40 independently-stale desks."
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->sweep();

        $this->assertTrue((int) $this->predicate('ingest_receiving', Predicates::FLEET)->true_count > 0);

        $this->advanceServerClock(400);
        $this->sweep();
        $this->advanceServerClock(15);
        $this->sweep();

        $fleet = $this->predicate('ingest_receiving', Predicates::FLEET);
        $this->assertSame(2, (int) $fleet->false_count);
        $this->assertNotNull($fleet->alarm_since, 'constant-false for 2 consecutive passes');
    }

    /**
     * § 5's second binding rule, checked as a set rather than one predicate at a time: a predicate
     * declared in § 5 and evaluated nowhere is a branch count that reads zero for ever, which is
     * the 30-day-dark shape this whole section exists to prevent.
     */
    public function test_job_7_all_seven_declared_predicates_have_a_writer(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->advanceServerClock(61 * 60);
        $this->stayAlive();
        $this->sweep();

        $recorded = DB::table('seat_predicates')->pluck('name')->unique()->sort()->values()->all();

        $this->assertSame(
            collect(Predicates::names())->sort()->values()->all(),
            $recorded,
            'every predicate § 5 declares must have an evaluation site that actually runs',
        );
    }

    private function nowMs(): int
    {
        return Clock::toMs(Clock::sql(now()));
    }
}

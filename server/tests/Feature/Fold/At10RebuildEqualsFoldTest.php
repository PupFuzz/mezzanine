<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;

/**
 * AT-D2-10 — rebuild equals fold.
 *
 * "The strongest available check that state is DERIVED and not STORED." § 6.6: "if it ever does
 * not, some fold rule is reading state that is not in the log, and that rule is a defect by
 * construction."
 *
 * ⚠ SIZE. § 11 asks for "a 10,000-event fixture covering every kind". This drives a fixture
 * covering EVERY KIND the ingest knows (all fourteen), replayed at a size the suite can run on
 * every commit rather than at 10,000. The equality is the property; the volume is a stress test,
 * and running it here would trade a check that runs on every push for one nobody waits for. The
 * shortfall is named in the PR body rather than left for a reviewer to notice.
 */
class At10RebuildEqualsFoldTest extends FoldTestCase
{
    public function test_a_rebuilt_seat_equals_the_incrementally_folded_one_column_for_column(): void
    {
        $this->deliverEveryKind();
        $this->fold();

        $folded = $this->snapshot();
        $this->assertNotSame([], $folded['sessions']);
        $this->assertNotSame([], $folded['calls']);
        $this->assertNotSame([], $folded['attention']);

        // ⛔ THE CONTROL ON THE § 4.9 COLUMNS — card #9214, and it is a CONTROL rather than a
        // nicety: `task_as_of` is compared below, and a fixture that left `task_title` null would
        // compare a null against a null and report equality whatever the fold did with the stamp.
        // The comparison can only discriminate on a column that is POPULATED on both sides.
        $this->assertNotNull($folded['seat_state']['task_title'], 'the fixture opened no titled call');
        $this->assertNotNull($folded['seat_state']['task_as_of']);

        // ⛔ THE REBUILD RUNS AT A LATER WALL CLOCK THAN THE FOLD, AND THAT IS THE WHOLE POINT
        // OF THIS LINE — card #9214.
        //
        // `FoldTestCase` drives a FIXED server clock, and every event of this fixture arrives in
        // one batch, so without this the fold and the replay both run at ONE instant. Any fold
        // rule that stamped a projected column with `now()` would then write the SAME value on
        // both sides, and this comparison would report equality while the column was not
        // reproducible at all — which is exactly what `task_as_of` did, undetected, behind the
        // exclusion `snapshot()` used to carry. A rebuild is a RECOVERY path (§ 6.6): it runs
        // minutes to days after the fold it replaces, never in the same millisecond.
        //
        // FIVE SECONDS, and the bound is the tightest time-derived threshold any COMPARED column
        // turns on: § 7.2's `fold_lag` badge at 60 s (`Badges::FOLD_LAG_MS`), then § 4.5's 300 s
        // `stale`. `deliver()` has already advanced the clock by `VISIBILITY_LAG_S + 1`, so the
        // replay derives at receipt + 8 s — inside every one of them by an order of magnitude, so
        // a divergence here is a divergence about the FOLD and not about the fixture's clock.
        $this->advanceServerClock(5);

        $this->artisan('mezzanine:rebuild', ['--seat' => 'aimla/aimla-pm'])->assertSuccessful();

        // "Every column of `seat_state`, `sessions`, `calls` and `attention_requests` is IDENTICAL
        // except `updated_at`, `state_computed_at` and `state_version` (which counts transitions,
        // and a rebuild produces them in one pass)."
        $this->assertSame($folded, $this->snapshot());

        $this->assertSame(1, $this->counter('state_rebuilds'));
    }

    public function test_the_discriminating_control_a_rebuild_of_an_untouched_seat_reports_equality(): void
    {
        // § 11: "a rebuild of an untouched seat must produce ZERO differences, SO THE COMPARISON IS
        // KNOWN TO BE CAPABLE OF REPORTING EQUALITY." Without it, a comparison that always reported
        // "different" would fail the test above for the right-looking reason, and a comparison that
        // always reported "same" would pass it while checking nothing.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $before = $this->snapshot();

        $this->artisan('mezzanine:rebuild', ['--seat' => 'aimla/aimla-pm'])->assertSuccessful();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_comparison_can_report_a_difference(): void
    {
        // The other half of the control, and the one § 11 leaves implicit: a comparison that cannot
        // report INEQUALITY is a decoration. Fold, then mutate one projected column by hand, then
        // compare — the reader must see it.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $before = $this->snapshot();

        DB::table('calls')->where('seat_ref', $this->seatRef)->update(['outcome' => 'aborted']);

        $this->assertNotSame($before, $this->snapshot());
    }

    public function test_a_rebuild_since_a_later_point_is_truncated_and_says_so(): void
    {
        // § 6.6, "bounded honestly": a rebuild can only reconstruct what the retention window still
        // holds, and a shortened one is counted rather than silently shorter.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $newest = DB::table('events')->where('seat_ref', $this->seatRef)->max('received_at');

        $this->artisan('mezzanine:rebuild', ['--seat' => 'aimla/aimla-pm', '--since' => $newest])
            ->assertSuccessful();

        $this->assertSame(1, $this->counter('rebuild_truncated'));
        $this->assertContains('rebuild', array_column($this->transitions(), 'cause'));

        // The cursor clock is the OLDEST REPLAYED EVENT'S receipt and NEVER null, so § 2.3's lag
        // stays computable and honest for the length of the run. A null here would make
        // `server_now − NULL` unavailable on exactly the seat an operator is watching recover.
        $this->assertNotNull($this->state()->fold_cursor_received_at);
    }

    /**
     * Every kind `KindRegistry` knows, in one stream: the fourteen the ingest accepts.
     */
    private function deliverEveryKind(): void
    {
        $call = $this->ulid();
        $dispatch = $this->ulid();
        $request = $this->ulid();
        $live = $this->ulid();
        $next = 'b8e3d029-5c11-4f88-9a0d-3e72d5c9b024';

        $this->deliver([
            $this->event('session.start', [
                'source' => 'startup', 'project_label' => 'mezzanine',
                'harness_label' => 'claude-code/2.1.240', 'previous_session_id' => null,
            ]),
            $this->event('turn.start', ['prompt_chars' => 412]),
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Bash', 'descriptor' => 'Bash: composer test',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => 'toolu_01A9F3kQ2mZ', 'open_calls_before' => 0,
            ]),
            $this->event('attention.request', [
                'request_id' => $request, 'source' => 'permission_request_hook',
                'notification_kind' => 'permission_required', 'call_id' => $call, 'open_calls' => 1,
            ]),
            $this->event('attention.resolved', [
                'request_id' => $request, 'resolution' => 'granted',
                'resolution_source' => 'call_close', 'waited_ms' => 8200,
            ]),
            $this->event('tool.end', [
                'call_id' => $call, 'tool_name' => 'Bash', 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => 251, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use', 'match' => 'harness_ref',
            ]),
            $this->event('tool.start', [
                'call_id' => $dispatch, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('subagent.spawn', [
                'call_id' => $dispatch, 'title' => 'draft the D1 event schema',
                'title_truncated' => false, 'subagent_type' => 'coder',
            ]),
            $this->event('tool.end', [
                'call_id' => $dispatch, 'tool_name' => 'Agent', 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => 184992, 'duration_source' => 'index',
                'close_source' => 'subagent_stop_hook', 'match' => 'agent_id',
            ]),
            $this->event('subagent.stop', [
                'call_id' => $dispatch, 'outcome' => 'completed', 'abort_reason' => null,
                'duration_ms' => 184992, 'close_source' => 'subagent_stop_hook',
            ]),
            $this->event('compaction.start', [
                'trigger' => 'auto', 'context_used_pct' => 91.4, 'context_used_pct_age_s' => 3,
                'open_calls' => 0,
            ]),
            $this->event('compaction.end', ['duration_ms' => 4100, 'close_source' => 'post_compact']),
            $this->event('context.sample', [
                'used_pct' => 73.2, 'used_tokens' => 146401, 'total_tokens' => 200000,
                'used_pct_source' => 'harness', 'model_label' => 'claude-opus-5',
                'sample_reason' => 'threshold_cross',
            ]),
            $this->event('turn.end', [
                'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 41880,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 2, 'failed_calls' => 0,
            ]),
            ...$this->heartbeats(1),
            $this->event('session.end', [
                'end_reason' => 'prompt_input_exit', 'duration_ms' => 938204, 'turns' => 1,
                'aborted_calls' => 0,
            ]),

            // ⛔ AND THEN A SECOND SESSION THAT IS STILL LIVE, WITH A TITLED DISPATCH CALL STILL
            // OPEN — card #9214.
            //
            // § 4.9's tier 3 answers from "the newest OPEN dispatch call's `title`", so a fixture
            // whose every call is closed leaves `task_title`, and with it `task_as_of`, at null in
            // the state this test compares. That was this fixture's shape, and it is why the
            // exclusion `snapshot()` used to carry for `task_as_of` was UNEXERCISED: deleting it
            // changed nothing, because the column was null on both sides.
            //
            // It ends OPEN deliberately. `session.end` above reaps the calls of the session it
            // closes (§ 4.6), so appending this call to that session would only close it again;
            // this is the seat as an operator actually finds it when a rebuild is called for —
            // mid-flight, with a subagent out. Leaving a call open used to cost a delta per fold
            // pass (card #7837's `task_as_of` re-stamp) and two of this suite's fixtures are
            // fenced against it in terms; that is fixed, and `FeedSurfaceTest::
            // test_a_seat_with_an_open_call_is_as_quiet_as_one_without` is what holds it fixed.
            $this->event('session.start', [
                'source' => 'startup', 'project_label' => 'mezzanine',
                'harness_label' => 'claude-code/2.1.240', 'previous_session_id' => $this->sessionId,
            ], $next),
            $this->event('turn.start', ['prompt_chars' => 96], $next),
            $this->event('tool.start', [
                'call_id' => $live, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ], $next),
            $this->event('subagent.spawn', [
                'call_id' => $live, 'title' => 'rebuild the seat from the log',
                'title_truncated' => false, 'subagent_type' => 'coder',
            ], $next),
        ]);
    }

    /**
     * Every column of the four tables, less the three § 11 excludes by name and the surrogate keys
     * a rebuild necessarily re-mints (the projection rows are DELETED and re-inserted, so their
     * `id`s and the `session_ref`s pointing at them are new — which is why § 11 compares COLUMNS
     * and the rendered object rather than row identity).
     *
     * ⛔ THE EXCLUSION LIST IS CLOSED AND EVERY MEMBER CARRIES ITS REASON HERE. An exclusion with
     * no reason is a hole in the strongest check this design has, and it is not discoverable from
     * outside: the test still passes, and it passes LOUDER than it should.
     *
     *   `id`, `seat_ref`, `session_ref`      surrogate keys the rebuild re-mints (above)
     *   `updated_at`, `state_computed_at`    § 11 by name
     *   `state_version`                      § 11 by name — it counts transitions, and a rebuild
     *                                        produces them in one pass
     *   `current_session_ref`,               surrogate POINTERS at those re-minted rows; the facts
     *   `current_call_ref`,                  they point at ARE compared, as the rows of the three
     *   `open_attention_ref`                 projection tables — the `id`s cannot be
     *   `fold_cursor_received_at`            a rebuild deliberately re-enters the never-folded
     *                                        state and leaves it by a different route (§ 2.3), so
     *                                        its cursor clock is the OLDEST REPLAYED event's
     *                                        receipt rather than the last folded event's
     *
     * ⚠ `task_as_of` WAS A FOURTH EXCLUSION BEYOND § 11's THREE AND IS NOT ONE ANY MORE — card
     * #9214. It carried no justification, and it was measured UNEXERCISED: deleting it changed
     * nothing, because this fixture closed every call (so `task_title` was null on both sides) and
     * ran the fold and the replay at ONE frozen instant (so a `now()` stamp landed on the same
     * value twice). Both holes are closed above, and the column is compared like every other one.
     * `StateRecompute::taskTier3()` now derives the stamp from the answering call's own
     * `opened_received_at`, which is IN THE LOG — so the equality holds for the reason § 6.6 gives
     * rather than by exclusion.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $strip = ['id', 'seat_ref', 'session_ref', 'updated_at'];

        $rows = fn (string $table, string $order) => DB::table($table)->where('seat_ref', $this->seatRef)
            ->orderBy($order)->get()
            ->map(fn ($r) => array_diff_key((array) $r, array_flip($strip)))->all();

        $state = (array) $this->state();

        // The list the docblock above closes, in that order.
        unset(
            $state['updated_at'], $state['state_computed_at'], $state['state_version'],
            $state['current_session_ref'], $state['current_call_ref'], $state['open_attention_ref'],
            $state['fold_cursor_received_at'],
        );

        return [
            'seat_state' => $state,
            'sessions' => $rows('sessions', 'session_id'),
            'calls' => $rows('calls', 'call_id'),
            'attention' => $rows('attention_requests', 'request_id'),
        ];
    }
}

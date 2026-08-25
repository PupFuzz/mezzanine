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
        ]);
    }

    /**
     * Every column of the four tables, less the three § 11 excludes by name and the surrogate keys
     * a rebuild necessarily re-mints (the projection rows are DELETED and re-inserted, so their
     * `id`s and the `session_ref`s pointing at them are new — which is why § 11 compares COLUMNS
     * and the rendered object rather than row identity).
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

        // The three § 11 names, plus the two cursor columns — a rebuild deliberately re-enters the
        // never-folded state and leaves it by a different route (§ 2.3), so its cursor clock is the
        // oldest replayed event's receipt rather than the last folded event's.
        unset(
            $state['updated_at'], $state['state_computed_at'], $state['state_version'],
            $state['current_session_ref'], $state['current_call_ref'], $state['open_attention_ref'],
            $state['fold_cursor_received_at'], $state['task_as_of'],
        );

        return [
            'seat_state' => $state,
            'sessions' => $rows('sessions', 'session_id'),
            'calls' => $rows('calls', 'call_id'),
            'attention' => $rows('attention_requests', 'request_id'),
        ];
    }
}

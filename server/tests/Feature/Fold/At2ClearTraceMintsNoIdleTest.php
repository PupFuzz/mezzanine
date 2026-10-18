<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;

/**
 * AT-D2-2 — the `/clear` trace mints no idle.
 *
 * "The D2 half of D1's headline test, and the gate on trusting the derived signal at all."
 * `docs/design/FLEET-STATE.md § 11`, driving § 10's worked trace end to end.
 */
class At2ClearTraceMintsNoIdleTest extends FoldTestCase
{
    public function test_the_clear_trace_mints_no_idle_in_hook_order_session_end_first(): void
    {
        $this->assertClearTrace(sessionStartFirst: false);
    }

    public function test_the_clear_trace_mints_no_idle_in_the_alternate_hook_order(): void
    {
        // D1 states that `SessionStart(clear)` may arrive BEFORE `SessionEnd(clear)` and that both
        // reap idempotently. In that ordering the reap events arrive under the `SessionStart`
        // invocation instead; the wire is the same sequence of kinds, so the fold's inputs are the
        // same and the state path must be identical (§ 10).
        $this->assertClearTrace(sessionStartFirst: true);
    }

    private function assertClearTrace(bool $sessionStartFirst): void
    {
        $this->deliver($this->clearKill($sessionStartFirst));
        $this->fold();

        $state = $this->state();

        // EXACTLY TWO ROWS — § 11 says so and says why: "because the fixture starts on a fresh
        // seat". § 10's table runs `state_version` 1…10 from a seat whose pre-E0 render is
        // `offline`.
        $this->assertSame(
            [
                ['from' => 'offline', 'to' => 'working', 'cause' => 'wire_event'],
                ['from' => 'working', 'to' => 'unknown', 'cause' => 'wire_event'],
            ],
            $this->transitions(),
        );

        // "No `idle` row at any version and no `idle` in any row's `from_render_state`."
        $rows = DB::table('seat_state_transitions')->where('seat_ref', $this->seatRef)->get();
        foreach ($rows as $row) {
            $this->assertNotSame('idle', $row->to_render_state);
            $this->assertNotSame('idle', $row->from_render_state);
        }

        $this->assertSame('unknown', $state->activity_state);
        $this->assertSame('turn_killed_by_clear', $state->unknown_reason);
        $this->assertSame(0, (int) $state->open_calls);

        // Both calls closed `aborted` / `session_cleared` with `close_source:
        // reap_session_boundary` — the REPORTER said it all. And no `close_source` beginning
        // `server_` anywhere: the server inferred nothing on this path, which is what makes § 10's
        // "the clear killed these" readable in the drill-down rather than "these ended".
        $calls = DB::table('calls')->where('seat_ref', $this->seatRef)->get();
        $this->assertCount(2, $calls);

        foreach ($calls as $call) {
            $this->assertSame('aborted', $call->outcome);
            $this->assertSame('session_cleared', $call->abort_reason);
            $this->assertSame('reap_session_boundary', $call->close_source);
            $this->assertStringStartsNotWith('server_', (string) $call->close_source);
        }

        // § 10 E2: the intern's label arrives on the `subagent.spawn` and is joined on `call_id`.
        $this->assertSame(
            'draft the D1 event schema',
            DB::table('calls')->where('seat_ref', $this->seatRef)->where('is_dispatch', true)->value('title'),
        );
    }

    public function test_the_discriminating_control_does_mint_idle(): void
    {
        // § 11: "the same fixture with the reap events' `outcome` changed to `completed` and
        // `aborted_call_ids` emptied → the seat DOES mint idle, proving the test measures the abort
        // discrimination and not the shape of the trace."
        $this->deliver($this->clearKill(completedInstead: true));
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state);
    }

    public function test_out_of_order_delivery_does_not_change_the_idle_decision(): void
    {
        // § 11's SECOND RED, as a green: "deliver E7's batch BEFORE E4–E6's → GREEN must be
        // unchanged, because the idle decision reads `aborted_call_ids` OFF THE EVENT. If it
        // changes, the derivation is reconstructing from the ledger and `D2-MUST` #4 is not being
        // honoured."
        $events = $this->clearKill();

        $this->deliver([...array_slice($events, 0, 4), ...array_slice($events, 7)]);  // E0–E3, E7–E9
        $this->deliver(array_slice($events, 4, 3));                                   // E4–E6, late
        $this->fold();

        $state = $this->state();
        $this->assertSame('unknown', $state->activity_state);
        $this->assertSame('turn_killed_by_clear', $state->unknown_reason);

        $idle = DB::table('seat_state_transitions')->where('seat_ref', $this->seatRef)
            ->where(fn ($q) => $q->where('to_render_state', 'idle')->orWhere('from_render_state', 'idle'))
            ->count();

        $this->assertSame(0, $idle, 'an idle was minted on the out-of-order path');
    }

    public function test_a_turn_end_omitting_background_tasks_open_does_not_mint_idle(): void
    {
        // The fail-safe direction, asserted rather than assumed. D1 § 6.4 declares
        // `background_tasks_open` NON-NULL, so an absent value means a producer that is not
        // conforming — and the two readings of that absence differ on the defect this plane exists
        // to prevent. Coercing to 0 satisfies rule 4 and mints `idle` on a seat whose subagent may
        // be running; leaving it null leaves rule 4 unsatisfied and the seat says it does not know.
        // § 4.8's first principle is that an ABSENCE never mints a state.
        $events = $this->cleanTurn();
        unset($events[3]['data']['background_tasks_open']);

        $this->deliver($events);
        $this->fold();

        $this->assertSame('unknown', $this->state()->activity_state,
            'an absent background-task count minted idle');

        // The CONTROL: the identical fixture WITH the field present at 0 does mint idle, so this
        // measures the absence and not the shape of the turn.
        $this->assertSame('idle', $this->foldOnASecondSeat($this->cleanTurn()));
    }

    private function foldOnASecondSeat(array $events): string
    {
        [$token, $seatRef] = $this->issueToken('aimla', 'control-seat');

        $this->deliver($events, token: $token, install: 'aimla', seat: 'control-seat');
        $this->fold();

        return $this->state($seatRef)->activity_state;
    }

    /**
     * CASE β — the background-task lifecycle (card #7337), and the assertion that is deliberately
     * NOT "no idle anywhere".
     */
    public function test_case_beta_no_idle_while_the_subagent_is_alive(): void
    {
        $events = $this->backgroundTaskTrace();

        // GREEN, FIRST HALF: no `idle` row while the subagent is alive — not on the parent's clean
        // `turn.end`, and not in the window between it and the subagent's first `tool.start`.
        // Delivered in two batches so the window between them is a real folded state rather than a
        // state the fold skipped over.
        $this->deliver(array_slice($events, 0, 5));   // …through the parent's clean turn.end
        $this->fold();

        $this->assertSame('unknown', $this->state()->activity_state,
            'the parent turn ended clean with background_tasks_open: 1 and the seat went idle');

        $this->assertSame(
            [
                ['from' => 'offline', 'to' => 'working', 'cause' => 'wire_event'],
                ['from' => 'working', 'to' => 'unknown', 'cause' => 'wire_event'],
            ],
            $this->transitions(),
        );

        // The subagent's first `tool.start` returns the seat to `working` (§ 4.4's NOT-an-exit row).
        $this->deliver(array_slice($events, 5, 1));
        $this->fold();
        $this->assertSame('working', $this->state()->activity_state);

        // GREEN, SECOND HALF: an `idle` row AFTER the `session.end` is a PASS. Nothing is running
        // by then, the session that owned the background task has ended, and *idle* is TRUE.
        // ⛔ § 11 forbids asserting its absence: "an acceptance test that demands `unknown` there is
        // demanding a state the seat is not in, and satisfying it would mean suppressing an honest
        // reading."
        $this->deliver(array_slice($events, 6));
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state);

        // FIVE rows, and the fourth is the one worth reading. The child call's `tool.end` closes
        // `C` while the session is still open, so `L` is still clean-but-background and rule 4 is
        // still false — the seat is `unknown` for that window, exactly as § 4.8 row 4 describes
        // ("`unknown` until the subagent's next `tool.start`", and symmetrically until the session
        // that owned the task ends). Only the `session.end` clears the count and makes rule 4 true.
        $this->assertSame(
            [
                ['from' => 'offline', 'to' => 'working', 'cause' => 'wire_event'],
                ['from' => 'working', 'to' => 'unknown', 'cause' => 'wire_event'],
                ['from' => 'unknown', 'to' => 'working', 'cause' => 'wire_event'],
                ['from' => 'working', 'to' => 'unknown', 'cause' => 'wire_event'],
                ['from' => 'unknown', 'to' => 'idle', 'cause' => 'wire_event'],
            ],
            $this->transitions(),
        );

        // The mechanism, asserted directly: `session.end` cleared the background count while
        // leaving the end reason and the aborted count standing. That asymmetry IS the card #7337
        // rule, and it is the thing the second RED breaks.
        $session = DB::table('sessions')->where('seat_ref', $this->seatRef)->first();
        $this->assertSame('stop_hook', $session->last_turn_end_reason);
        $this->assertSame(0, (int) $session->last_turn_aborted_count);
        $this->assertSame(0, (int) $session->last_turn_background_tasks_open);
    }

    /**
     * CASE γ — a `/clear` while a background subagent runs (D1 § 8.3, card#11817). The harness
     * re-homes the subagent to the new session, so the reporter CARRIES its open dispatch call and
     * names it in `session.end.carried_call_ids`; the server must leave that call open across the
     * session close, so the intern stays drawn until the call's own close under the new session.
     */
    public function test_case_gamma_a_carried_dispatch_stays_open_across_the_clear(): void
    {
        [$events, $dispatch] = $this->carriedClearTrace([fn (string $a) => [$a]]);

        $this->deliver(array_slice($events, 0, 6));   // …through the session.start after the clear
        $this->fold();

        $this->assertNull($this->callRow($dispatch)->closed_at, 'the session close swept the carried dispatch');
        $this->assertSame(1, $this->openDispatches());
        $this->assertSame(0, $this->counter('session_close_orphans'));
        $this->assertSame(1, $this->counter('session_close_carried'));
        $this->assertSame(0, $this->counter('ignored_unknown_fields'), 'the ingest does not know carried_call_ids');
        $this->assertSame('working', $this->state()->activity_state);

        $this->deliver(array_slice($events, 6));
        $this->fold();

        $a = $this->callRow($dispatch);
        $this->assertNotNull($a->closed_at);
        $this->assertSame('subagent_stop_hook', $a->close_source);
        $this->assertSame(0, $this->openDispatches());
        $this->assertSame(0, DB::table('calls')->where('seat_ref', $this->seatRef)
            ->where('close_source', 'like', 'server\_%')->count());
        $this->assertSame(0, $this->counter('late_close_cross_session'));
    }

    public function test_case_gamma_controls_an_absent_or_malformed_carry_is_swept(): void
    {
        // An older reporter: nothing carried, so the session close sweeps the dispatch as before.
        [$events, $dispatch] = $this->carriedClearTrace([fn (string $a) => []]);
        $this->deliver(array_slice($events, 0, 6));
        $this->fold();
        $this->assertSame('server_session_close', $this->callRow($dispatch)->close_source);
        $this->assertSame(1, $this->counter('session_close_orphans'));
        $this->assertSame(0, $this->counter('session_close_carried'));
    }

    public function test_case_gamma_controls_a_non_array_carry_runs_both_sweeps(): void
    {
        [$events, $dispatch] = $this->carriedClearTrace([fn (string $a) => $a]);
        $this->deliver(array_slice($events, 0, 6));
        $this->fold();
        $this->assertSame('server_session_close', $this->callRow($dispatch)->close_source);
        $this->assertSame(0, $this->counter('session_close_carried'));
    }

    public function test_case_gamma_controls_a_member_off_the_ulid_format_is_ignored(): void
    {
        [$events, $dispatch] = $this->carriedClearTrace([fn (string $a) => [$a, 'not-a-ulid']]);
        $this->deliver(array_slice($events, 0, 6));
        $this->fold();
        $this->assertNull($this->callRow($dispatch)->closed_at);
        $this->assertSame(1, $this->counter('session_close_carried'));
    }

    public function test_case_gamma_the_carried_count_is_seat_wide_across_a_second_clear(): void
    {
        // The second clear's session.end names A again while A's `session_ref` is still the FIRST
        // session — the server never re-homes the row — so a per-session count would read 0.
        [$events, $dispatch] = $this->carriedClearTrace([fn (string $a) => [$a], fn (string $a) => [$a]]);
        $this->deliver(array_slice($events, 0, 8));   // …through the second clear
        $this->fold();
        $this->assertNull($this->callRow($dispatch)->closed_at);
        $this->assertSame(2, $this->counter('session_close_carried'));
        $this->assertSame(0, $this->counter('session_close_orphans'));
    }

    /**
     * Case γ's stream: the parent dispatches A and ends its turn clean with the subagent running,
     * then one `session.end(clear)` + `session.start(clear)` pair per entry of `$clears`, each
     * `session.end` carrying what its closure returns for A; then the subagent's next call and A's
     * own close, both under the last session.
     *
     * @param  list<\Closure(string): mixed>  $clears
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    private function carriedClearTrace(array $clears): array
    {
        $a = $this->ulid();
        $c = $this->ulid();
        $events = [
            $this->event('turn.start', ['prompt_chars' => 88]),
            $this->event('tool.start', [
                'call_id' => $a, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => 'toolu_01Carried', 'open_calls_before' => 0,
            ]),
            $this->event('subagent.spawn', [
                'call_id' => $a, 'title' => 'design the clear carry', 'title_truncated' => false,
                'subagent_type' => 'designer',
            ]),
            $this->event('turn.end', [
                'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 4100,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 1, 'tool_calls' => 1, 'failed_calls' => 0,
            ]),
        ];

        $old = $this->sessionId;
        foreach ($clears as $i => $carry) {
            $new = sprintf('c1ea7000-0000-4000-8000-%012d', $i + 1);
            $events[] = $this->event('session.end', [
                'end_reason' => 'clear', 'duration_ms' => 9000, 'turns' => 1, 'aborted_calls' => 0,
                'carried_call_ids' => $carry($a),
            ], $old);
            $events[] = $this->event('session.start', [
                'source' => 'clear', 'project_label' => 'mezzanine',
                'harness_label' => 'claude-code/2.1.296', 'previous_session_id' => $old,
            ], $new);
            $old = $new;
        }

        array_push($events,
            $this->event('tool.start', [
                'call_id' => $c, 'tool_name' => 'Bash', 'descriptor' => 'Bash: ls',
                'descriptor_truncated' => false, 'agent_scope' => 'subagent', 'parent_call_id' => $a,
                'harness_call_ref' => null, 'open_calls_before' => 1,
            ], $old),
            $this->event('tool.end', [
                'call_id' => $c, 'tool_name' => 'Bash', 'outcome' => 'completed', 'abort_reason' => null,
                'duration_ms' => 31, 'duration_source' => 'harness', 'close_source' => 'post_tool_use',
                'match' => 'harness_ref',
            ], $old),
            $this->event('tool.end', [
                'call_id' => $a, 'tool_name' => 'Agent', 'outcome' => 'completed', 'abort_reason' => null,
                'duration_ms' => 600000, 'duration_source' => 'index', 'close_source' => 'subagent_stop_hook',
                'match' => 'agent_id',
            ], $old),
            $this->event('subagent.stop', [
                'call_id' => $a, 'outcome' => 'completed', 'abort_reason' => null, 'duration_ms' => 600000,
                'close_source' => 'subagent_stop_hook',
            ], $old),
        );

        return [$events, $a];
    }

    private function callRow(string $callId): object
    {
        return DB::table('calls')->where('seat_ref', $this->seatRef)->where('call_id', $callId)->first();
    }

    private function openDispatches(): int
    {
        return DB::table('calls')->where('seat_ref', $this->seatRef)
            ->where('is_dispatch', true)->whereNull('closed_at')->count();
    }
}

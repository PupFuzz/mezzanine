<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;

/**
 * AT-D2-5 — blocked has an exit, and every exit is the seat saying something.
 *
 * The operator's ruling of 2026-09-14 (card#9527): "Mezzanine should assume agent is stuck until it
 * gets another status update from that agent." So a request has NO TIMER — the 60-minute reporter
 * and server ceilings are gone, and so is the leaving-live resolution — and § 4.4's exits are all
 * events: the request's own `attention.resolved`, its session closing, and the seat-activity exit
 * this file drives most of. The time-side half of the ruling — four hours with heartbeats, and a
 * seat that goes offline and comes back — needs the sweeper and is in `SweepJobsTest` (job 3,
 * retired, and job 5).
 */
class At5BlockedTest extends FoldTestCase
{
    public function test_blocked_outranks_an_open_call_and_is_cleared_by_its_resolution(): void
    {
        $events = $this->blockedPair();

        // Delivered in two halves so the BLOCKED state is a folded state and not a state the fold
        // passed through — § 11: "assert the seat renders `blocked` WHILE ITS `call_id` IS STILL
        // OPEN, or the state D1 requires is unreachable on the path that produces it".
        $this->deliver(array_slice($events, 0, 3));
        $this->fold();

        $state = $this->state();
        $this->assertSame('blocked', $state->activity_state);
        $this->assertSame('blocked', $state->render_state);
        $this->assertSame(1, (int) $state->open_calls, 'the call the request is about must still be open');
        $this->assertNotNull($state->open_attention_ref);

        // § 4.3's precedence rule 1 over rule 3, stated loudly in the document because it is the
        // one place two upstream rules are simultaneously satisfiable. A seat waiting on a human is
        // not working, whatever its call ledger says.
        $this->deliver(array_slice($events, 3));
        $this->fold();

        $request = DB::table('attention_requests')->where('seat_ref', $this->seatRef)->first();
        $this->assertNotNull($request->resolved_at);
        $this->assertSame('granted', $request->resolution);
        $this->assertSame('call_close', $request->resolution_source);
        $this->assertSame(8200, (int) $request->waited_ms);

        // The call is still open, so the seat returns to `working` — not to `idle`.
        $this->assertSame('working', $this->state()->activity_state);
    }

    public function test_a_session_ending_while_blocked_clears_the_request(): void
    {
        // § 4.4's third exit. D1 emits `attention.resolved(session_ended)` AFTER the boundary
        // event, and the server also closes the request when the session closes — so a lost
        // resolution cannot strand the state. This drives the server-side half by ending the
        // session with no resolution on the wire at all.
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->assertSame('blocked', $this->state()->activity_state);

        $this->deliver([$this->event('session.end', [
            'end_reason' => 'logout', 'duration_ms' => 1000, 'turns' => 1, 'aborted_calls' => 1,
        ])]);
        $this->fold();

        $request = DB::table('attention_requests')->where('seat_ref', $this->seatRef)->first();
        $this->assertSame('session_ended', $request->resolution);
        $this->assertSame('session_end', $request->resolution_source);

        // A state with an entry edge and no exit edge is a one-way trapdoor. This one has an exit.
        $this->assertNotSame('blocked', $this->state()->activity_state);
    }

    public function test_a_second_request_while_one_is_open_never_opens_a_second_blocked(): void
    {
        // § 4.4's NOT-an-exit row: "at most one is open per session; a second is stored as a
        // duplicate and counted `attention_request_duplicate_server`, never opening a second
        // *blocked*". D1 counts the reporter-side case; this is the server's INDEPENDENT
        // observation of the same thing, and the two disagreeing means one of them is wrong.
        $events = $this->blockedPair(requestOnly: true);
        $first = $events[2]['data']['request_id'];

        $this->deliver($events);
        $this->fold();

        $this->deliver([$this->event('attention.request', [
            'request_id' => $this->ulid(), 'source' => 'notification_hook',
            'notification_kind' => 'input_awaited', 'call_id' => null, 'open_calls' => 1,
        ])]);
        $this->fold();

        $this->assertSame(1, $this->counter('attention_request_duplicate_server'));
        $this->assertCount(2, DB::table('attention_requests')->where('seat_ref', $this->seatRef)->get());
        $this->assertSame('blocked', $this->state()->activity_state);

        // The OLDEST unresolved request holds the state, so `blocked_since` dates the request that
        // actually opened `blocked`.
        $this->assertSame(
            $first,
            DB::table('attention_requests')->where('id', $this->state()->open_attention_ref)->value('request_id'),
        );
    }

    /**
     * § 4.4's seat-activity exit, on the path the 60-minute ceiling used to cover: the reporter's
     * own `attention.resolved` never arrives, and the seat's next `tool.end` is what ends the wait.
     *
     * RED on the code before card#9527: nothing on the fold resolved a request on activity, so this
     * seat stayed `blocked` until the sweeper's ceiling an hour later.
     */
    public function test_the_seats_next_tool_end_resolves_it_when_no_resolution_arrives(): void
    {
        $events = $this->blockedPair(requestOnly: true);
        $call = $events[1]['data']['call_id'];

        $this->deliver($events);
        $this->fold();
        $this->assertSame('blocked', $this->state()->activity_state);

        $this->deliver([$this->toolEnd($call)]);
        $this->fold();

        $request = $this->requestRow();
        $this->assertSame('seat_activity', $request->resolution);
        $this->assertSame('server_seat_activity', $request->resolution_source);
        $this->assertSame(1000, (int) $request->waited_ms, 'seat clock: the tool.end is one fixture second later');
        $this->assertNotSame('blocked', $this->state()->activity_state);
    }

    /** A prompt — `turn.start` — is the agent's next status update too, and resolves it the same way. */
    public function test_the_seats_next_prompt_resolves_it(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $this->deliver([$this->event('turn.start', ['prompt_chars' => 9])]);
        $this->fold();

        $this->assertSame('seat_activity', $this->requestRow()->resolution);
        $this->assertSame('working', $this->state()->activity_state);
    }

    /**
     * The card's acceptance: "a new session on the seat resolves the old session's request". A
     * harness killed while waiting sends no `SessionEnd`, so the old session never closes on the
     * wire, and the next thing the seat says about it is a new session starting.
     *
     * RED on the code before card#9527, and worse than the ceiling's hour: the request is
     * seat-wide state (§ 4.3 rule 1 reads every session), so the restarted agent's desk would have
     * rendered `blocked` over its new work until the ceiling fired.
     */
    public function test_a_new_session_on_the_seat_resolves_the_old_sessions_request(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $restarted = 'b81e0f37-2c4d-4a9b-8e61-5d2c7a3f9e10';
        $this->deliver([$this->event('session.start', [
            'source' => 'startup', 'project_label' => 'mezzanine', 'harness_label' => null,
            'previous_session_id' => null,
        ], $restarted)]);
        $this->fold();

        $this->assertSame('seat_activity', $this->requestRow()->resolution);
        $this->assertNull(
            DB::table('sessions')->where('seat_ref', $this->seatRef)->where('session_id', $this->sessionId)->value('ended_at'),
            'the old session is not closed by this — only its wait is',
        );
        $this->assertNotSame('blocked', $this->state()->activity_state);
    }

    /**
     * The seat ruling on card#9527 (comment 10579): a seat with two terminals is two agents, so
     * work in session B is not session A's status update. B's `tool.end` and `turn.end` leave A's
     * request open and the seat `blocked`; A's own next event still resolves it.
     *
     * SEEN RED on 63524bd6, the seat-wide rule this narrowed: B's `tool.end` resolved A's request
     * `seat_activity` while A was still waiting.
     */
    public function test_another_sessions_activity_leaves_the_request_blocked(): void
    {
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $other = 'd5e6f7a8-1b2c-4d3e-8f90-a1b2c3d4e5f6';
        $call = $this->ulid();
        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 7], $other),
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Read', 'descriptor' => 'Read: b.md',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ], $other),
            $this->event('tool.end', [
                'call_id' => $call, 'tool_name' => 'Read', 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => 40, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use', 'match' => 'sole_open',
            ], $other),
            $this->event('turn.end', [
                'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 900,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 1, 'failed_calls' => 0,
            ], $other),
        ]);
        $this->fold();

        $this->assertNull($this->requestRow()->resolved_at, "session B's activity is not session A's status update");
        $this->assertSame('blocked', $this->state()->activity_state);

        // The control: the waiting session's own next prompt still resolves it.
        $this->deliver([$this->event('turn.start', ['prompt_chars' => 3])]);
        $this->fold();
        $this->assertSame('seat_activity', $this->requestRow()->resolution);
    }

    /**
     * THE CONTROLS — each a seat event that is NOT the waiting agent's next status update, each
     * delivered and asserted on its own so a red names the control that tripped. Each was SEEN to
     * fail under its own plant: adding its kind to `Projector::SEAT_ACTIVITY_KINDS`, dropping the
     * `opened_at` bound, dropping the session scope, dropping the `reap_reporter_restart` exclusion,
     * or dropping the `inferred_silence` skip on the session close.
     *
     *  - a heartbeat: the REPORTER saying it is alive, which a waiting agent's reporter keeps doing;
     *  - another call's `tool.start`: the call awaiting permission can itself open after the request;
     *  - another session's `inferred_silence` close: the flusher's inference, not the agent's act;
     *  - a `tool.end` stamped BEFORE the request on the seat clock: delivered late, it is not news;
     *  - the waiting call's `tool.end` from the flusher's START-UP reap (`reap_reporter_restart`):
     *    the reporter restarting or upgrading, not the agent doing anything (card#9527 review F1);
     *  - the waiting session's OWN `inferred_silence` close, which an un-upgraded reporter still
     *    emits at 90 minutes: an inference about silence, and the wait is why it is silent (F2).
     */
    public function test_events_that_are_not_the_agents_next_status_update_leave_it_blocked(): void
    {
        $events = $this->blockedPair(requestOnly: true);
        $waitingCall = $events[1]['data']['call_id'];
        $openedMs = $this->clockMs;

        $this->deliver($events);
        $this->fold();

        $other = 'c4d2e8a1-7b3f-4e5d-9c0a-1f2e3d4c5b6a';
        $controls = [
            'a reporter heartbeat' => $this->heartbeats(2),
            "another call's tool.start" => [$this->event('tool.start', [
                'call_id' => $this->ulid(), 'tool_name' => 'Read', 'descriptor' => 'Read: a.md',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 1,
            ])],
            "another session's inferred_silence close" => [$this->event('session.end', [
                'end_reason' => 'inferred_silence', 'duration_ms' => null, 'turns' => 0, 'aborted_calls' => 0,
            ], $other)],
            'a tool.end stamped before the request' => [$this->toolEnd($this->ulid(), $openedMs - 5000)],
            "the flusher start-up reap's tool.end (reap_reporter_restart)" => [$this->event('tool.end', [
                'call_id' => $waitingCall, 'tool_name' => 'Write', 'outcome' => 'aborted',
                'abort_reason' => 'reporter_restart', 'duration_ms' => null, 'duration_source' => 'none',
                'close_source' => 'reap_reporter_restart', 'match' => 'reap',
            ])],
            "the waiting session's own inferred_silence close" => [$this->event('session.end', [
                'end_reason' => 'inferred_silence', 'duration_ms' => null, 'turns' => 1, 'aborted_calls' => 0,
            ])],
        ];

        foreach ($controls as $label => $batch) {
            $this->deliver($batch);
            $this->fold();

            $this->assertNull($this->requestRow()->resolved_at, "{$label} resolved the request");
            $this->assertSame('blocked', $this->state()->activity_state, "{$label} un-blocked the seat");
        }
    }

    /**
     * The seat-activity exit is an INFERENCE and the reporter's resolution an OBSERVATION, so the
     * second relabels the first (D1 § 12.5). On an ordinary approval the reporter emits the
     * `tool.end` FIRST and its `attention.resolved(granted)` straight after (D1 § 6.13), so this is
     * the order every approval takes through the fold.
     */
    public function test_the_reporters_resolution_relabels_the_seat_activity_inference(): void
    {
        $events = $this->blockedPair(requestOnly: true);
        $call = $events[1]['data']['call_id'];
        $requestId = $events[2]['data']['request_id'];

        $this->deliver($events);
        $this->fold();

        $this->deliver([
            $this->toolEnd($call),
            $this->event('attention.resolved', [
                'request_id' => $requestId, 'resolution' => 'granted',
                'resolution_source' => 'call_close', 'waited_ms' => 1000,
            ]),
        ]);
        $this->fold();

        $request = $this->requestRow();
        $this->assertSame('granted', $request->resolution);
        $this->assertSame('call_close', $request->resolution_source);
        $this->assertNotSame('blocked', $this->state()->activity_state);
    }

    /**
     * § 7.2's `attention_long_wait`: counted ONCE, on the request's first resolution, when the wait
     * reached 60 minutes — and not by the reporter's relabel that follows, which is the same wait.
     * The 59-minute request is the control: SEEN to fail by lowering the threshold below it.
     */
    public function test_a_wait_of_an_hour_or_more_is_counted_once(): void
    {
        $events = $this->blockedPair(requestOnly: true);
        $call = $events[1]['data']['call_id'];
        $requestId = $events[2]['data']['request_id'];
        $openedMs = $this->clockMs;

        $this->deliver($events);
        $this->fold();

        $this->deliver([
            $this->toolEnd($call, $openedMs + 59 * 60_000),
        ]);
        $this->fold();
        $this->assertSame(0, $this->counter('attention_long_wait'), '59 minutes is not a long wait');

        $events = $this->blockedPair(requestOnly: true);
        $call = $events[1]['data']['call_id'];
        $requestId = $events[2]['data']['request_id'];
        $openedMs = $this->clockMs;

        $this->deliver($events);
        $this->fold();

        $this->deliver([
            $this->toolEnd($call, $openedMs + 60 * 60_000),
            $this->event('attention.resolved', [
                'request_id' => $requestId, 'resolution' => 'granted',
                'resolution_source' => 'call_close', 'waited_ms' => 3_600_000,
            ], null, $openedMs + 60 * 60_000),
        ]);
        $this->fold();

        $this->assertSame(1, $this->counter('attention_long_wait'));
        $this->assertSame('granted', DB::table('attention_requests')->where('request_id', $requestId)->value('resolution'));
    }

    public function test_the_discriminating_control_a_seat_that_is_never_blocked_never_renders_blocked(): void
    {
        // § 11: reachable only because D1 gates the `Notification` hook on `notification_type`; "if
        // it fails, the gate has been lost upstream and every seat is about to render `blocked` on
        // `auth_success`".
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state);
        $this->assertSame(0, DB::table('attention_requests')->where('seat_ref', $this->seatRef)->count());
    }

    private function requestRow(): object
    {
        return DB::table('attention_requests')->where('seat_ref', $this->seatRef)->orderByDesc('id')->first();
    }

    /** @return array<string, mixed> */
    private function toolEnd(string $call, ?int $seatClockMs = null): array
    {
        return $this->event('tool.end', [
            'call_id' => $call, 'tool_name' => 'Write', 'outcome' => 'completed',
            'abort_reason' => null, 'duration_ms' => 900, 'duration_source' => 'harness',
            'close_source' => 'post_tool_use', 'match' => 'sole_open',
        ], null, $seatClockMs);
    }
}

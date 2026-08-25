<?php

namespace Tests\Feature\Fold;

use App\Fold\SeatFacts;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-11 — out-of-order batches converge.
 *
 * `D2-MUST` #4: state transitions are ordered by `(event_time, seq_epoch, seq)`, NEVER by arrival
 * order. Arrival order decides WHEN work happens and never WHICH VALUE WINS.
 */
class At11OutOfOrderTest extends FoldTestCase
{
    public function test_three_batches_delivered_3_1_2_converge_on_the_in_order_state(): void
    {
        $events = $this->clearKill();
        $batches = [array_slice($events, 0, 4), array_slice($events, 4, 3), array_slice($events, 7)];

        // A control run on its own seat, delivered in order.
        [$controlToken, $controlSeat] = $this->issueToken('aimla', 'control-seat');

        $this->deliver($batches[2]);
        $this->deliver($batches[0]);
        $this->deliver($batches[1]);
        $this->fold();

        $out = $this->comparable($this->seatRef);

        // The same events, IN ORDER, through a second seat — re-addressed to it, because D1
        // § 12.1's identity-binding rule refuses a body naming a seat the token is not bound to.
        $this->deliverAs($controlToken, $batches[0]);
        $this->deliverAs($controlToken, $batches[1]);
        $this->deliverAs($controlToken, $batches[2]);
        $this->fold();

        $this->assertSame($this->comparable($controlSeat), $out);
    }

    public function test_a_tool_end_before_its_tool_start_creates_the_call_closed_and_the_late_open_does_not_reopen_it(): void
    {
        // D1 § 8.6: "create the entry already closed; a later `tool.start` for it DOES NOT REOPEN
        // it, and counts `late_open`". A reopened call renders the seat `working` forever.
        $call = $this->ulid();

        $start = $this->event('tool.start', [
            'call_id' => $call, 'tool_name' => 'Bash', 'descriptor' => 'Bash: composer test',
            'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
            'harness_call_ref' => null, 'open_calls_before' => 0,
        ]);
        $end = $this->event('tool.end', [
            'call_id' => $call, 'tool_name' => 'Bash', 'outcome' => 'completed', 'abort_reason' => null,
            'duration_ms' => 251, 'duration_source' => 'harness', 'close_source' => 'post_tool_use',
            'match' => 'harness_ref',
        ]);

        $this->deliver([$end]);
        $this->fold();

        $this->assertSame(0, (int) $this->state()->open_calls);

        $this->deliver([$start]);
        $this->fold();

        $this->assertSame(1, $this->counter('late_open'));
        $this->assertSame(0, (int) $this->state()->open_calls, 'the late tool.start reopened a closed call');

        // The non-close fields ARE filled, because in-order delivery would have carried them and
        // this test's whole claim is that the two orders converge.
        $row = DB::table('calls')->where('seat_ref', $this->seatRef)->first();
        $this->assertSame('Bash: composer test', $row->descriptor);
        $this->assertSame('completed', $row->outcome);
    }

    public function test_a_superseded_turn_end_does_not_overwrite_a_newer_one(): void
    {
        $newerAt = $this->clockMs + 600_000;

        $newer = $this->event('turn.end', [
            'end_reason' => 'session_cleared', 'api_error_type' => null, 'duration_ms' => 10,
            'open_calls_at_end' => 1, 'aborted_call_ids' => [$this->ulid()],
            'stop_hook_active' => false, 'background_tasks_open' => 0, 'tool_calls' => 1,
            'failed_calls' => 0,
        ], seatClockMs: $newerAt);

        $older = $this->event('turn.end', [
            'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 10,
            'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
            'background_tasks_open' => 0, 'tool_calls' => 1, 'failed_calls' => 0,
        ], seatClockMs: $newerAt - 300_000);

        $this->deliver([$newer]);
        $this->fold();
        $this->assertSame('unknown', $this->state()->activity_state);

        // The older, CLEAN turn.end arrives late. Applying by arrival order would let it win, the
        // seat's last-turn record would REGRESS, and a killed turn would render `idle` — the false
        // idle arriving through the comparator instead of through the rule.
        $this->deliver([$older]);
        $this->fold();

        $this->assertSame('unknown', $this->state()->activity_state);
        $this->assertSame('turn_killed_by_clear', $this->state()->unknown_reason);
        $this->assertSame('session_cleared', DB::table('sessions')
            ->where('seat_ref', $this->seatRef)->value('last_turn_end_reason'));
    }

    public function test_an_out_of_order_session_start_still_lands_its_labels(): void
    {
        // The case that decides § 6.5's guards must be PER FIELD GROUP and not one triple per row:
        // a `session.start` arriving after that session's `turn.end` is older on the comparator, so
        // a row-level guard would refuse it wholesale and `project_label` / `start_source` would be
        // lost — and the final state would not equal in-order delivery, which is this test's GREEN.
        $sessionStart = $this->event('session.start', [
            'source' => 'startup', 'project_label' => 'mezzanine',
            'harness_label' => 'claude-code/2.1.240', 'previous_session_id' => null,
        ]);

        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->deliver([$sessionStart]);
        $this->fold();

        $session = DB::table('sessions')->where('seat_ref', $this->seatRef)->first();
        $this->assertSame('mezzanine', $session->project_label);
        $this->assertSame('startup', $session->start_source);
        $this->assertSame('claude-code/2.1.240', $session->harness_label);

        // …and it did not un-end the turn.
        $this->assertSame('idle', $this->state()->activity_state);
        $this->assertFalse((bool) $session->turn_open);
    }

    public function test_the_epoch_is_part_of_the_comparator(): void
    {
        // § 11's SECOND RED, driven as a green: an event from a NEW `seq_epoch` with a LOWER `seq`
        // than the previous epoch's newest, IN THE SAME MILLISECOND. With a `(event_time, seq)`
        // comparator the newer event loses; with `(event_time, seq_epoch, seq)` it wins. This is
        // the only way to see the refinement `D2-MUST` #4 names.
        $at = $this->clockMs + 60_000;

        $old = $this->event('turn.end', [
            'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 1,
            'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
            'background_tasks_open' => 0, 'tool_calls' => 0, 'failed_calls' => 0,
        ], seatClockMs: $at);
        $old['seq'] = 90_000;

        $new = $this->event('turn.end', [
            'end_reason' => 'session_cleared', 'api_error_type' => null, 'duration_ms' => 1,
            'open_calls_at_end' => 1, 'aborted_call_ids' => [$this->ulid()],
            'stop_hook_active' => false, 'background_tasks_open' => 0, 'tool_calls' => 0,
            'failed_calls' => 0,
        ], seatClockMs: $at);
        $new['seq'] = 5;   // a LOWER seq — the epoch restarted the counter

        $this->deliver([$old], ['seq_epoch' => '01K3T0000A5N7M2X9V4B6D0FGH']);
        $this->fold();
        $this->assertSame('idle', $this->state()->activity_state);

        // A ULID sorts by mint time, so the newer epoch sorts above the older one — which is what
        // makes the three-part key total across a reset.
        $this->deliver([$new], ['seq_epoch' => '01K9ZZZZZA5N7M2X9V4B6D0FGH']);
        $this->fold();

        $this->assertSame('unknown', $this->state()->activity_state);
        $this->assertSame('turn_killed_by_clear', $this->state()->unknown_reason);

        // D1 § 10.2: a new epoch is a RE-NUMBERING, not a loss — counted, badged `epoch_reset`,
        // and deliberately not alarmed. Without a new epoch a reset counter would look like a
        // 48,000-event gap.
        $this->assertSame(1, $this->counter('seq_epoch_change'));
        $this->assertContains('epoch_reset', json_decode($this->state()->server_badges, true));
        $this->assertSame(0, $this->counter('seq_gap'), 'the epoch reset was counted as a gap');
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function deliverAs(string $token, array $events): void
    {
        $this->deliver($events, token: $token, install: 'aimla', seat: 'control-seat');
    }

    /**
     * The projections, less the identifiers and the bookkeeping that legitimately differ between
     * two seats and two runs.
     *
     * @return array<string, mixed>
     */
    private function comparable(int $seatRef): array
    {
        $strip = ['id', 'seat_ref', 'session_ref', 'applied_seq', 'opened_received_at',
            'closed_received_at', 'orphan_due_at', 'started_received_at', 'updated_at'];

        $rows = fn (string $table, string $order) => DB::table($table)->where('seat_ref', $seatRef)
            ->orderBy($order)->get()
            ->map(fn ($r) => array_diff_key((array) $r, array_flip($strip)))->all();

        $facts = SeatFacts::versionBearing($seatRef);
        unset($facts['action'], $facts['subagents'], $facts['session'], $facts['task']);

        // `activity.last_received_at` is a SERVER-clock receipt and the two runs were delivered at
        // different server times, so it legitimately differs. The seat-clock member and the kind
        // are what convergence is a claim about, and they stay in.
        $facts['activity'] = [$facts['activity'][0], $facts['activity'][2]];

        return [
            'facts' => $facts,
            'sessions' => $rows('sessions', 'session_id'),
            'calls' => $rows('calls', 'call_id'),
            'attention' => $rows('attention_requests', 'request_id'),
        ];
    }
}

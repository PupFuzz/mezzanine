<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;

/**
 * card#9346 — a wire string that breaks its published FORMAT costs that one field, never the event.
 *
 * The ingest refuses a `data` field over its published BYTE bound and checks no pattern (D1 § 12.1
 * step 10), so a value off its pattern reaches the fold. Where the field's column is
 * `CHARACTER SET ascii` (D2 § 6.4), a non-ASCII value used to fail the write under the store's strict
 * mode, roll the fold transaction back and quarantine the event (§ 6.5's poison-event rule) — for a
 * heartbeat that is `last_heartbeat_received_at` rolled back with it, and a healthy seat drifting to
 * `stale` with a `derivation_error` badge. The operator's ruling on the card (2026-09-13, D18, option
 * (a)): the fold stores `null` for the non-conforming field, counts it, and lands the rest.
 *
 * Every case below drives the real ingest and the real fold on MariaDB, and asserts that nothing was
 * quarantined, that `format_refused.<field>` rose by exactly one, and that the field is `null` (or
 * D1's own stand-in where the column cannot hold one) while the REST of the event landed — or, where
 * the refused field is the event's key (`call_id`, `request_id`), that nothing landed for it.
 *
 * The population is every wire string the fold writes into an ASCII column; D2 § 6.5 states it and
 * names the command that re-prints it. `console_url`, the case the primitive was generalised from,
 * is held by `ConsoleUrlReachesOperatorsOnlyTest`.
 */
class AFieldOffItsFormatCostsOneFieldTest extends FoldTestCase
{
    /** `é` is two bytes of UTF-8 and no byte of ASCII: under every byte bound, outside every ASCII column. */
    private const NON_ASCII = 'é';

    // ── reporter.heartbeat ───────────────────────────────────────────────────────────────────

    /** The card's own case: the liveness fact lands, the seat stays live, the name is `null`. */
    public function test_a_non_ascii_protocol_agent_name_costs_the_name_and_not_the_heartbeat(): void
    {
        $this->assertHeartbeatFieldRefused('protocol_agent_name', 'pm'.self::NON_ASCII);
    }

    public function test_a_non_ascii_protocol_agent_role_costs_the_role_and_not_the_heartbeat(): void
    {
        $this->assertHeartbeatFieldRefused('protocol_agent_role', 'pm'.self::NON_ASCII);
    }

    /**
     * FORMAT, not merely charset: `PM` is ASCII and fits the column, and it is not a slug (D1 § 6.0:
     * lowercase `[a-z0-9-]`). A conforming reporter treats it as a malformed declaration and sends
     * `null` (D1 § 3.1), so a stored `PM` would be a name no roster member can equal.
     */
    public function test_an_ascii_name_off_the_slug_pattern_is_refused_too(): void
    {
        $this->assertHeartbeatFieldRefused('protocol_agent_name', 'PM');
    }

    // ── session.start ────────────────────────────────────────────────────────────────────────

    /**
     * `harness_label` is read by the projection AND by the seat recompute, three times in all for one
     * event, and is counted once: the count is per event and field, not per read.
     */
    public function test_a_non_ascii_harness_label_costs_the_label_on_both_rows_and_is_counted_once(): void
    {
        $this->deliver([$this->event('session.start', [
            'source' => 'startup', 'project_label' => 'mezzanine',
            'harness_label' => 'claude-code/2.1.240'.self::NON_ASCII, 'previous_session_id' => null,
        ])]);
        $this->fold();

        $session = $this->sessionRow();
        $this->assertNotNull($session->started_at, 'the session.start did not land');
        $this->assertSame('mezzanine', $session->project_label, 'the rest of the session.start did not land');
        $this->assertNull($session->harness_label);
        $this->assertNull($this->state()->harness_label);
        $this->assertLanded('harness_label');
    }

    public function test_a_previous_session_id_off_the_session_id_pattern_costs_that_field(): void
    {
        $this->deliver([$this->event('session.start', [
            'source' => 'clear', 'project_label' => 'mezzanine',
            'harness_label' => 'claude-code/2.1.240', 'previous_session_id' => 'e3c1'.self::NON_ASCII,
        ])]);
        $this->fold();

        $session = $this->sessionRow();
        $this->assertSame('claude-code/2.1.240', $session->harness_label, 'the rest of the session.start did not land');
        $this->assertNull($session->previous_session_id);
        $this->assertLanded('previous_session_id');
    }

    // ── tool.start / tool.end / subagent.spawn ───────────────────────────────────────────────

    public function test_a_parent_call_id_or_harness_call_ref_off_format_costs_that_field_and_not_the_call(): void
    {
        $call = $this->ulid();

        $this->deliver([$this->toolStart($call, [
            'parent_call_id' => strtolower($this->ulid()),
            'harness_call_ref' => 'toolu_'.self::NON_ASCII,
        ])]);
        $this->fold();

        $row = $this->callRow($call);
        $this->assertNotNull($row, 'the tool.start did not land');
        $this->assertSame('Bash', $row->tool_name);
        $this->assertNull($row->parent_call_id);
        $this->assertNull($row->harness_call_ref);
        $this->assertLanded('parent_call_id', 'harness_call_ref');
    }

    /**
     * `calls.tool_name` is NOT NULL, so `null` is not a value the call can land with. D1 § 6.5 publishes
     * the stand-in for exactly this case — a name that failed its pattern is `INVALID_TOOL_NAME` — so
     * the fold applies D1's own rule and the call lands. A MISSING name is a different fact and keeps
     * § 6.5's poison-event answer (`Projector`'s note on fabricated fallbacks).
     */
    public function test_a_tool_name_off_its_pattern_lands_as_d1s_own_stand_in(): void
    {
        $call = $this->ulid();
        $synth = $this->ulid();

        $this->deliver([
            $this->toolStart($call, ['tool_name' => 'Bash'.self::NON_ASCII]),
            // A close with no open creates the row from the tool.end alone (§ 10.2), through the
            // second write site of the column.
            $this->event('tool.end', [
                'call_id' => $synth, 'tool_name' => 'Bash'.self::NON_ASCII, 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => 3, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use', 'match' => 'synthesized',
            ]),
        ]);
        $this->fold();

        $this->assertSame('INVALID_TOOL_NAME', $this->callRow($call)?->tool_name);
        $this->assertSame('INVALID_TOOL_NAME', $this->callRow($synth)?->tool_name);
        $this->assertLanded();
        $this->assertSame(2, $this->counter('format_refused.tool_name'));
    }

    public function test_a_non_ascii_subagent_type_costs_the_type_and_not_the_title(): void
    {
        $call = $this->ulid();

        $this->deliver([
            $this->toolStart($call, ['tool_name' => 'Agent']),
            $this->event('subagent.spawn', [
                'call_id' => $call, 'title' => 'audit the fold', 'title_truncated' => false,
                'subagent_type' => 'coder'.self::NON_ASCII,
            ]),
        ]);
        $this->fold();

        $row = $this->callRow($call);
        $this->assertSame('audit the fold', $row?->title, 'the rest of the subagent.spawn did not land');
        $this->assertNull($row->subagent_type);
        $this->assertLanded('subagent_type');
    }

    /**
     * A `call_id` is the event's KEY, so a refused one leaves nothing to land — exactly as a missing one
     * has always done — but it is counted now, and it no longer takes the seat's fold with it.
     */
    public function test_a_call_id_off_the_ulid_pattern_is_counted_and_lands_no_call(): void
    {
        $this->deliver([$this->toolStart(self::NON_ASCII.str_repeat('A', 24))]);
        $this->fold();

        $this->assertSame(0, DB::table('calls')->where('seat_ref', $this->seatRef)->count());
        $this->assertLanded('call_id');
    }

    // ── attention.request ────────────────────────────────────────────────────────────────────

    public function test_an_attention_call_id_off_format_costs_that_field_and_a_request_id_off_it_lands_nothing(): void
    {
        $request = $this->ulid();

        $this->deliver([
            $this->event('attention.request', [
                'request_id' => $request, 'source' => 'notification_hook',
                'notification_kind' => 'input_awaited', 'call_id' => 'x'.self::NON_ASCII, 'open_calls' => 0,
            ]),
            $this->event('attention.request', [
                'request_id' => 'not-a-ulid', 'source' => 'notification_hook',
                'notification_kind' => 'input_awaited', 'call_id' => null, 'open_calls' => 0,
            ], 'b8e3d029-5c11-4f88-9a0d-3e72d5c9b024'),
        ]);
        $this->fold();

        $rows = DB::table('attention_requests')->where('seat_ref', $this->seatRef)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($request, $rows[0]->request_id);
        $this->assertNull($rows[0]->call_id);
        $this->assertLanded('call_id', 'request_id');
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function assertHeartbeatFieldRefused(string $field, string $value): void
    {
        $beat = $this->heartbeats(1)[0];
        $beat['data'][$field] = $value;

        $this->deliver([$beat]);
        $this->fold();

        $state = $this->state();
        $this->assertNotNull($state->last_heartbeat_received_at, 'the heartbeat\'s liveness fact did not land');
        $this->assertSame(86_213, (int) $state->reporter_uptime_s, 'the rest of the heartbeat did not land');
        $this->assertSame('live', $state->link_state, 'a healthy seat is not live after its heartbeat');
        $this->assertNull($state->{$field});
        $this->assertLanded($field);
    }

    /**
     * Nothing was quarantined, and each named field was counted exactly once.
     */
    private function assertLanded(string ...$refused): void
    {
        $this->assertSame(0, (int) $this->state()->fold_errors, 'an event was quarantined (§ 6.5\'s poison-event rule)');
        $this->assertSame(0, $this->counter('fold_error'));

        foreach ($refused as $field) {
            $this->assertSame(1, $this->counter('format_refused.'.$field), "`format_refused.{$field}` did not rise by exactly one");
        }
    }

    /** @param  array<string, mixed>  $over */
    private function toolStart(string $callId, array $over = []): array
    {
        return $this->event('tool.start', $over + [
            'call_id' => $callId, 'tool_name' => 'Bash', 'descriptor' => null,
            'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
            'harness_call_ref' => null, 'open_calls_before' => 0,
        ]);
    }

    private function sessionRow(): object
    {
        return DB::table('sessions')->where('seat_ref', $this->seatRef)->where('session_id', $this->sessionId)->first();
    }

    private function callRow(string $callId): ?object
    {
        return DB::table('calls')->where('seat_ref', $this->seatRef)->where('call_id', $callId)->first();
    }
}

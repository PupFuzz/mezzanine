<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;

/**
 * AT-D2-1 — idle is minted by exactly one rule.
 *
 * `docs/design/FLEET-STATE.md § 11`. Rule 4 of § 4.3 is `D2-MUST` #1 transcribed as a predicate,
 * and it is the ONLY rule in the document that can produce `idle`.
 */
class At1IdleMintedByOneRuleTest extends FoldTestCase
{
    public function test_a_clean_turn_mints_idle_from_the_turn_end(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $state = $this->state();
        $this->assertSame('idle', $state->activity_state);
        $this->assertSame('idle', $state->render_state);
        $this->assertNull($state->unknown_reason);

        // § 11's "one transition row with `cause: wire_event` pointing at the `turn.end`'s
        // `events.id`" — ONE ROW POINTING AT THE TURN END, not one row in total. The fixture starts
        // on a FRESH seat, which § 10 states renders `offline` (rule 1 of § 4.5: `last_receipt_at
        // IS NULL`), so the first event necessarily transitions `offline → working` as well.
        // AT-D2-2 says the same thing in terms for its own fixture: "two rows, because the fixture
        // starts on a fresh seat".
        $this->assertSame(
            [
                ['from' => 'offline', 'to' => 'working', 'cause' => 'wire_event'],
                ['from' => 'working', 'to' => 'idle', 'cause' => 'wire_event'],
            ],
            $this->transitions(),
        );

        $turnEndId = DB::table('events')->where('seat_ref', $this->seatRef)
            ->where('kind', 'turn.end')->value('id');

        $idleRow = DB::table('seat_state_transitions')
            ->where('seat_ref', $this->seatRef)->where('to_render_state', 'idle')->first();

        $this->assertSame((int) $turnEndId, (int) $idleRow->cause_event_ref);
    }

    public function test_a_failed_call_does_not_block_idle(): void
    {
        // D1 § 6.4: a call that ran and errored is a CLOSED call — its lifecycle completed, the
        // agent read the error and carried on. `aborted_call_ids` names calls the harness never
        // closed, and this one was closed. Reading a failed call as blocking would leave a real
        // seat permanently `unknown`, because most turns contain one.
        $this->deliver($this->failedCall());
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state);
        $this->assertSame(1, (int) DB::table('sessions')
            ->where('seat_ref', $this->seatRef)->value('last_turn_failed_calls'));
    }

    public function test_idle_survives_its_session(): void
    {
        // § 11's `clean_turn_then_exit` — the discriminating fixture for § 4.3's SEAT-SCOPED `L`.
        // The `idle` was minted by the `turn.end`, and a `session.end` falsifies neither fact rule
        // 4 reads, so it cannot un-mint it: rendering `unknown` here would replace a positive
        // observation ("the agent said it finished") with an absence of one.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $before = $this->transitions();

        $this->deliver([$this->event('session.end', [
            'end_reason' => 'prompt_input_exit', 'duration_ms' => 938204, 'turns' => 1,
            'aborted_calls' => 0,
        ])]);
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state);
        $this->assertSame('idle', $this->state()->render_state);
        $this->assertSame($before, $this->transitions(), 'the session.end wrote a transition row');

        $session = DB::table('sessions')->where('seat_ref', $this->seatRef)->first();
        $this->assertSame('stop_hook', $session->last_turn_end_reason);
        $this->assertSame(0, (int) $session->last_turn_aborted_count);
    }

    public function test_end_reason_other_is_not_a_degradation(): void
    {
        // D1 § 6.2 calls `other` "a common value, not a residue" — a non-interactive `claude -p`
        // session ends this way and it was the MAJORITY of D1's own capture run — and forbids
        // reading it as a degradation signal.
        $this->deliver($this->cleanTurn());
        $this->fold();

        // `accepted` is excluded because DELIVERING the event moves it — that is the ingest
        // counting a batch, not this plane reading `other` as a degradation. Every other counter
        // is in scope, which is what the claim is about.
        $degradation = fn () => DB::table('seat_counters')->where('seat_ref', $this->seatRef)
            ->where('name', '!=', 'accepted')->pluck('value', 'name')->all();

        $countersBefore = $degradation();

        $this->deliver([$this->event('session.end', [
            'end_reason' => 'other', 'duration_ms' => 100, 'turns' => 1, 'aborted_calls' => 0,
        ])]);
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state);
        $this->assertSame([], json_decode($this->state()->server_badges, true));
        $this->assertSame($countersBefore, $degradation());
    }

    public function test_the_discriminating_control_measures_the_predicate_not_the_turn_end(): void
    {
        // § 11: "replay `clean_turn` with the `turn.end`'s `end_reason` changed to `session_ended`
        // → `unknown`, not `idle`. The test measures the PREDICATE, not the presence of a
        // `turn.end`." Without this control, a rule that minted idle from any turn ending at all
        // would pass every GREEN above.
        $events = $this->cleanTurn();
        $events[3]['data']['end_reason'] = 'session_ended';

        $this->deliver($events);
        $this->fold();

        $this->assertSame('unknown', $this->state()->activity_state);
        $this->assertSame('turn_ended_with_session', $this->state()->unknown_reason);
    }
}

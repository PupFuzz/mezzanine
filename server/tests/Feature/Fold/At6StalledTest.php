<?php

namespace Tests\Feature\Fold;

use App\Fold\SeatFacts;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-6 — stalled is a state with three exits.
 *
 * ⚠ SCOPE. The third exit — the seat LEAVING LIVE, at `stale` or `offline` — is the SWEEPER's
 * write (§ 4.5's leaving-live clear), and the sweeper is built by neither half of card #7339. Two
 * of § 11's GREENs and its second RED (the one-pass jump that buys the `or offline` term) are
 * therefore NOT covered here, and the PR body says so. The two wire exits are.
 */
class At6StalledTest extends FoldTestCase
{
    /** @return list<array<string, mixed>> */
    private function rateLimitedTurn(?string $sessionId = null): array
    {
        return [
            $this->event('turn.start', ['prompt_chars' => 40], $sessionId),
            $this->event('turn.end', [
                'end_reason' => 'api_error', 'api_error_type' => 'rate_limit', 'duration_ms' => 1200,
                'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 0, 'failed_calls' => 0,
            ], $sessionId),
        ];
    }

    public function test_an_api_error_turn_end_mints_stalled_carrying_its_error_type(): void
    {
        $this->deliver($this->rateLimitedTurn());
        $this->fold();

        $this->assertSame('stalled', $this->state()->activity_state);
        $this->assertSame('stalled', $this->state()->render_state);

        // `D2-MUST` #1 requires the TYPE to reach the consumer: "`stalled` carries `api_error_type`
        // so the drill-down can say WHICH error". § 11 says to assert the wire field rather than
        // the column, "because a column the snapshot does not serialize discharges nothing" — the
        // snapshot is Part B's, so what is asserted here is the value the version-bearing fact set
        // carries, which is the one Part B's serializer reads.
        $this->assertSame('rate_limit', SeatFacts::versionBearing($this->seatRef)['api_error_type']);
        $this->assertSame('rate_limit', DB::table('sessions')
            ->where('seat_ref', $this->seatRef)->value('api_error_type'));
    }

    public function test_exit_one_the_sessions_next_turn_start_clears_it(): void
    {
        $this->deliver($this->rateLimitedTurn());
        $this->fold();
        $this->assertSame('stalled', $this->state()->activity_state);

        $this->deliver([$this->event('turn.start', ['prompt_chars' => 9])]);
        $this->fold();

        $this->assertSame('working', $this->state()->activity_state);

        $session = DB::table('sessions')->where('seat_ref', $this->seatRef)->first();
        $this->assertNull($session->stalled_since, 'the flag survived a turn the seat is visibly running');
        $this->assertSame('turn_start', $session->stalled_cleared_by);
    }

    public function test_exit_two_an_inferred_silence_session_end_leaves_unknown_and_never_idle(): void
    {
        $this->deliver($this->rateLimitedTurn());
        $this->fold();

        // The flusher's 90-minute `inferred_silence` close — D1 § 6.2's one INFERRED member, and
        // the exit that bounds `stalled` without inventing a second timer. § 11 requires the
        // outcome be `unknown` (`stalled_session_ended`) and NOT `idle`: the API refused the last
        // thing this seat tried and nothing since has said otherwise, which is an honest unknown
        // and not a quiet desk.
        $this->deliver([$this->event('session.end', [
            'end_reason' => 'inferred_silence', 'duration_ms' => 5_400_000, 'turns' => 1,
            'aborted_calls' => 0,
        ])]);
        $this->fold();

        $state = $this->state();
        $this->assertSame('unknown', $state->activity_state);
        $this->assertSame('stalled_session_ended', $state->unknown_reason);
        $this->assertNotSame('idle', $state->activity_state);

        // The clearer IS recorded even though `S` went false through its second term. § 4.6 is
        // explicit that a close which made `S` false without recording WHO cleared it would send
        // `unknown_reason_for(L)` to its catch-all row with no record of the clearer.
        $this->assertSame('session_end', DB::table('sessions')
            ->where('seat_ref', $this->seatRef)->value('stalled_cleared_by'));
    }

    public function test_stalled_is_per_session_and_one_stalled_session_takes_the_seat(): void
    {
        // § 4.4: "`stalled` is per SESSION, not per seat: a seat running two terminals can have one
        // rate-limited session and one healthy one, and the derivation's precedence takes `stalled`
        // if ANY session of the seat is stalled — because a rate-limited fleet is a thing an
        // operator acts on and hiding it behind a second healthy session would be the same collapse
        // D1 refuses when it declines to fold `api_error` into `unknown`."
        $other = 'c9d4e1b2-7a33-4c55-8e21-9f04b7d3a610';

        $this->deliver($this->rateLimitedTurn());
        $this->deliver($this->cleanTurn($other));
        $this->fold();

        $this->assertSame('stalled', $this->state()->activity_state);
    }

    public function test_the_discriminating_control_measures_api_error_and_not_the_presence_of_a_turn_end(): void
    {
        // § 11: "a `turn.end(stop_hook, [])` on the same seat → `idle`".
        $this->deliver($this->cleanTurn());
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state);
    }
}

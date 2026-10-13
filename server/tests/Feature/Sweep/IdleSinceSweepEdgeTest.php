<?php

namespace Tests\Feature\Sweep;

use App\Fold\Clock;

/**
 * card#9418 — an `idle` edge the SWEEPER mints carries the sweep pass's own server-clock instant.
 *
 * Every other `idle_since` test drives an edge the fold mints, where the value is the minting event's
 * `received_at`. But § 4.4's entry into `idle` is "rule 4 becomes true", and the sweeper changes
 * facts rule 4 reads with no event behind it: here § 4.6's orphan close takes `C` to 0 under a turn
 * that ended clean. There is no event to read a receipt from, so the instant is the pass's own —
 * still the server clock, still the moment the record entered `idle`, which is what the consumer's
 * `server_time − idle_since` age needs.
 */
class IdleSinceSweepEdgeTest extends SweepTestCase
{
    public function test_an_orphan_close_that_makes_a_seat_idle_stamps_the_pass_instant(): void
    {
        // A turn that ended CLEAN while one call was still open: L is clean, C == 1 ⇒ working.
        $this->deliver([
            ...$this->openCall(),
            $this->event('turn.end', [
                'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 4000,
                'open_calls_at_end' => 1, 'aborted_call_ids' => [], 'stop_hook_active' => false,
                'background_tasks_open' => 0, 'tool_calls' => 1, 'failed_calls' => 0,
            ]),
        ]);
        $this->fold();

        $this->assertSame('working', $this->state()->activity_state, 'the control: the open call keeps it working');
        $this->assertNull($this->state()->idle_since);

        // Past the 15-minute ordinary orphan ceiling, transport kept live so job 2 — not
        // quiescence — is what closes the call.
        $this->advanceServerClock(16 * 60);
        $this->stayAlive();
        $this->assertSame('working', $this->state()->activity_state);

        $passAt = Clock::sql(now());
        $this->sweep();

        $this->assertSame('idle', $this->state()->activity_state, 'the orphan close did not make the seat idle');
        $this->assertSame(Clock::wire($passAt), Clock::wire($this->state()->idle_since),
            'a sweeper-minted idle edge carries the pass instant');
    }
}

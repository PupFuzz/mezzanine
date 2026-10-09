<?php

namespace Tests\Feature\Sweep;

use Illuminate\Support\Facades\DB;

/**
 * Which clock offline quiescence stamps its closes on (card#11559).
 *
 * `sessions.last_turn_ended_at` and `sessions.ended_at` are SEAT-clock columns: every wire close
 * writes the closing event's `event_time`, and the fold orders the next seat event against them
 * (`Projector::groupIsOlder`), as § 4.3's `L` selection orders one session's turn record against
 * another's. Until card#11559 quiescence wrote the SERVER's `now` into both, so an event the seat
 * stamped before that server instant — spooled through a network outage, or sent by a seat whose
 * clock runs behind — was refused as older than a close the seat never made, and a clean `turn.end`
 * rendered `unknown` / `session_closed_turn_open` instead of `idle`.
 *
 * Quiescence now stamps the seat clock: one millisecond after the newest fact the seat had
 * delivered, the earliest instant its close can have happened at (FLEET-STATE.md § 4.6).
 */
class QuiescenceClockTest extends SweepTestCase
{
    /** The seat goes dark for 1000 s on the server's clock; its own clock does not move. */
    private function outagePastOffline(): void
    {
        $this->advanceServerClock(1000);
        $this->sweep();

        $session = $this->sessionRow();
        $this->assertSame('server_offline', $session->closed_by);
        $this->assertSame('server_session_close', $session->last_turn_end_reason);
        $this->assertSame('offline', $this->state()->render_state);
    }

    /** A clean `turn.end(stop_hook, [])` with no call aborted and nothing in the background. */
    private function cleanTurnEnd(?string $sessionId = null): array
    {
        return $this->event('turn.end', [
            'end_reason' => 'stop_hook', 'api_error_type' => null, 'duration_ms' => 9000,
            'open_calls_at_end' => 0, 'aborted_call_ids' => [], 'stop_hook_active' => false,
            'background_tasks_open' => 0, 'tool_calls' => 0, 'failed_calls' => 0,
        ], $sessionId);
    }

    public function test_a_turn_end_spooled_through_an_outage_lands_after_quiescence_and_renders_idle(): void
    {
        $this->deliver([$this->event('turn.start', ['prompt_chars' => 40])]);
        $this->fold();

        // The agent finishes its turn while the network is down: the reporter spools the
        // `turn.end`, stamped on the seat clock BEFORE the quiescence the server is about to write.
        $spooled = [$this->cleanTurnEnd()];

        $this->outagePastOffline();

        $this->deliver($spooled);
        $this->fold();
        $this->sweep();

        $session = $this->sessionRow();
        $this->assertSame('stop_hook', $session->last_turn_end_reason, 'the observed turn.end overrides the inferred close');
        $this->assertSame('wire', $session->turn_close_source);

        $state = $this->state();
        $this->assertSame('idle', $state->render_state);
        $this->assertSame('idle', $state->activity_state);
        $this->assertNull($state->unknown_reason);
    }

    public function test_a_turn_start_spooled_through_an_outage_reopens_the_turn(): void
    {
        $this->deliver([$this->event('turn.start', ['prompt_chars' => 40])]);
        $this->fold();

        // One turn ends and the next begins while the network is down.
        $spooled = [$this->cleanTurnEnd(), $this->event('turn.start', ['prompt_chars' => 12])];

        $this->outagePastOffline();

        $this->deliver($spooled);
        $this->fold();
        $this->sweep();

        $session = $this->sessionRow();
        $this->assertTrue((bool) $session->turn_open, 'the spooled turn.start is newer than anything the seat had said');
        $this->assertSame('working', $this->state()->render_state);
    }

    public function test_a_seat_whose_clock_runs_two_minutes_behind_returns_from_offline_working_then_idle(): void
    {
        // No outage at all: the lid closes and opens, and the seat's clock is 120 s behind the
        // server's throughout, so its first events after the lid opens are stamped before the
        // server instant at which quiescence ran.
        $this->clockMs -= 120_000;

        $this->deliver([$this->event('turn.start', ['prompt_chars' => 40])]);
        $this->fold();

        $this->advanceServerClock(1000);
        $this->clockMs += 1_000_000;
        $this->sweep();
        $this->assertSame('server_offline', $this->sessionRow()->closed_by);

        $this->advanceServerClock(30);
        $this->deliver([$this->event('turn.start', ['prompt_chars' => 12])]);
        $this->fold();
        $this->sweep();

        $this->assertTrue((bool) $this->sessionRow()->turn_open);
        $this->assertSame('working', $this->state()->render_state);

        $this->deliver([$this->cleanTurnEnd()]);
        $this->fold();
        $this->sweep();

        $this->assertSame('stop_hook', $this->sessionRow()->last_turn_end_reason);
        $this->assertSame('idle', $this->state()->render_state);
    }

    /**
     * The other reader of the stamp: § 4.3's `L` is the seat's turn record with the greatest
     * `last_turn_ended_at`, ACROSS sessions. A quiesced turn was still open at every fact the seat
     * delivered before it went dark, so its record must sort after all of them — including another
     * session's clean `turn.end` that was the seat's very last word. Stamping the quiesced session's
     * OWN newest fact, or the seat's newest fact without the millisecond, puts that clean record on
     * top and mints `idle` out of nothing but the seat going quiet (§ 4.8: an absence never mints
     * a state).
     */
    public function test_a_quiesced_turn_sorts_after_every_fact_the_seat_delivered_before_it(): void
    {
        $other = 'b3c4d5e6-0000-4000-8000-000000000002';

        // Session A starts a turn and goes quiet (thinking); session B runs a clean turn after it.
        $this->deliver([$this->event('turn.start', ['prompt_chars' => 40])]);
        $this->deliver($this->cleanTurn($other));
        $this->fold();
        $this->assertSame('working', $this->state()->render_state);

        $this->outagePastOffline();

        // The seat comes back with nothing to say: a heartbeat only.
        $this->stayAlive();
        $this->sweep();

        $a = DB::table('sessions')->where('seat_ref', $this->seatRef)->where('session_id', $this->sessionId)->first();
        $b = DB::table('sessions')->where('seat_ref', $this->seatRef)->where('session_id', $other)->first();
        $this->assertGreaterThan($b->last_turn_ended_at, $a->last_turn_ended_at);

        $state = $this->state();
        $this->assertSame('unknown', $state->render_state);
        $this->assertSame('session_closed_turn_open', $state->unknown_reason);
    }

    /**
     * card#11561 — the quiesced close is an INFERENCE, so the seat's real `turn.end` for that turn
     * supersedes it even when it is stamped before the seal. Here it is older than the seal because
     * it arrives OUT OF ORDER: another session's later turn was delivered first, so the seal sits
     * after it. In order this `turn.end` would have closed the turn before the seat went quiet and
     * quiescence would have found nothing to close; refusing it as older than a close the seat never
     * made renders `unknown` where in-order delivery renders `idle`.
     */
    public function test_a_late_turn_end_older_than_the_seal_supersedes_the_quiesced_turn_close(): void
    {
        $other = 'b3c4d5e6-0000-4000-8000-000000000002';

        $this->deliver([$this->event('turn.start', ['prompt_chars' => 40])]);
        $late = $this->cleanTurnEnd();                // stamped now, sent later
        $this->deliver($this->cleanTurn($other));      // stamped after it, delivered first
        $this->fold();

        $this->outagePastOffline();

        $this->deliver([$late]);
        $this->fold();
        $this->sweep();

        $session = $this->sessionRow();
        $this->assertSame('wire', $session->turn_close_source, 'the inferred close outranked the observed turn.end');
        $this->assertSame('stop_hook', $session->last_turn_end_reason);
        $this->assertSame('server_offline', $session->closed_by, 'an event older than the close re-opened the session');

        $state = $this->state();
        $this->assertSame('idle', $state->activity_state);
        $this->assertNull($state->unknown_reason);
    }
}

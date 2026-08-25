<?php

namespace Tests\Feature\Fold;

use App\Fold\Clock;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-5 — blocked has an exit.
 *
 * ⚠ SCOPE. § 11's test has five GREENs; the two that belong to the SWEEPER — the 60-minute server
 * ceiling and the leaving-live resolution — are not built by either half of card #7339 and are not
 * asserted here. The PR body says so rather than letting the file's name imply coverage. What is
 * here is every exit the WIRE carries, plus the server-side session close that § 4.4 requires "so
 * a lost resolution cannot strand the state".
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

        // The OLDEST unresolved request holds the state, so the 60-minute ceiling that will bound
        // it is the one belonging to the request that actually opened `blocked`.
        $this->assertSame(
            $first,
            DB::table('attention_requests')->where('id', $this->state()->open_attention_ref)->value('request_id'),
        );
    }

    public function test_the_ceiling_is_materialized_at_sixty_minutes_from_the_seat_clock(): void
    {
        // § 4.7: the attention ceiling is measured from the request's own `event_time` — the SEAT
        // clock — and deliberately not from receipt, because the REPORTER owns the competing timer
        // and fires at 60 min on its own clock. Using receipt would make the server clear first on
        // every skewed seat and mint a `server_ceiling` for a request the reporter was about to
        // resolve properly. The sweeper that FIRES this is out of scope; the materialized column it
        // will range-scan is not.
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();

        $request = DB::table('attention_requests')->where('seat_ref', $this->seatRef)->first();

        $this->assertSame(
            60 * 60 * 1000,
            Clock::toMs($request->ceiling_at) - Clock::toMs($request->opened_at),
        );
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
}

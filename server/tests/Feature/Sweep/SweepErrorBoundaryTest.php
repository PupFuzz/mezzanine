<?php

namespace Tests\Feature\Sweep;

use Illuminate\Support\Facades\DB;

/**
 * **One seat's failure costs one desk, and the process survives it** —
 * `docs/design/FLEET-STATE.md § 2.1`'s "individually restartable" read as what it actually
 * requires of the loop.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE DEGRADATION THIS FILE IS FOR, AND WHY THE TRANSACTION WAS NEVER IT. The per-seat
 * `DB::transaction` bounds what a failing seat WRITES; it does nothing about where the throw goes.
 * Without an error boundary in the loop, one seat's raise leaves the `foreach`, leaves `pass()`,
 * leaves `SweepCommand::handle()` and exits the process — and under the supervisor § 2.1 requires,
 * that is a crash loop in which EVERY seat's time-derived transitions freeze. § 2.2 names that
 * outcome as the one degradation "that can leave a dead seat rendering `working`", and it would be
 * reached here by the fleet-wide route rather than the per-seat one.
 *
 * ⚠ THE POISON IS A REAL, DESIGNED RAISE AND NOT AN INJECTED STUB. `SeatFacts::foldLagMs()` throws
 * on a seat whose `fold_cursor_received_at` was never seeded while its `head_event_id` has moved —
 * § 2.3's state, which AT-D2-21's fourth RED drives directly, and which the design REQUIRES to
 * raise rather than `COALESCE` to a healthy-looking zero. It sits on this seat's recompute and on
 * its `fold_current` evaluation, so it is on the sweep's per-seat path twice. Mocking a throw would
 * have proved the catch catches; using the raise the design itself installs proves the loop
 * survives the failure it actually has.
 */
class SweepErrorBoundaryTest extends SweepTestCase
{
    public function test_one_poisoned_seat_neither_stops_the_others_nor_kills_the_pass(): void
    {
        // Three seats, all live, all folded. The first and last are the innocent bystanders whose
        // time-derived transitions must still advance.
        [$aToken, $aRef] = $this->issueToken('aimla', 'desk-a');
        [$cToken, $cRef] = $this->issueToken('aimla', 'desk-c');

        $this->deliver($this->cleanTurn('a-session'), token: $aToken, seat: 'desk-a');
        $this->deliver($this->cleanTurn('b-session'));
        $this->deliver($this->cleanTurn('c-session'), token: $cToken, seat: 'desk-c');
        $this->fold();

        $this->assertSame('idle', $this->state($aRef)->render_state);
        $this->assertSame('idle', $this->state($cRef)->render_state);

        // POISON THE MIDDLE SEAT — § 2.3's unseeded cursor clock, exactly as AT-D2-21's fourth RED
        // constructs it: the head has moved and the ingest's one-shot seed did not run.
        $this->deliver($this->heartbeats(1));
        DB::table('seat_state')->where('seat_ref', $this->seatRef)
            ->update(['fold_cursor_received_at' => null]);

        // Past § 4.5's `offline` threshold: every seat is now owed a time-derived transition, so a
        // seat left un-visited is a seat left rendering something false.
        $this->advanceServerClock(1_000);

        $result = $this->sweep();

        // THE PASS COMPLETED. Before the boundary this line was unreachable: the raise propagated
        // out of `pass()` and the test died with it.
        $this->assertSame(3, $result->seats, 'every seat is visited, failures included');
        $this->assertSame(1, $result->failed);
        $this->assertTrue($result->partial());

        // THE OTHER TWO DESKS ADVANCED. This is the property, not the survival of the process:
        // a boundary that swallowed the throw and also skipped the rest of the loop would pass the
        // assertions above and fail these.
        $this->assertSame('offline', $this->state($aRef)->render_state);
        $this->assertSame('offline', $this->state($cRef)->render_state);

        // THE FAILURE IS COUNTED AND ATTRIBUTED, so a partially-failing pass is not silent. § 7.2's
        // `fold_error` is the precedent — the other per-seat derivation failure, stored the same
        // way.
        $this->assertSame(1, $this->counter('sweep_seat_error', $this->seatRef));
        $this->assertSame(0, $this->counter('sweep_seat_error', $aRef));

        // …and the poisoned seat itself did NOT advance, which is what "costs one desk" means. It
        // is still rendering what it rendered before, and that is the honest outcome of a seat
        // whose derivation cannot be computed.
        $this->assertNotSame('offline', $this->state($this->seatRef)->render_state);
    }

    /**
     * The pass STILL STAMPS `sweep_last_run_at`, and that is deliberate rather than an oversight.
     *
     * § 8.2.4 reads that field as "the sweeper ran" and § 2.2 turns a stale one into `fleet.sweep:
     * stalled`. Withholding the stamp because one desk raised would report a DEAD SWEEPER — a
     * larger and different claim than the true one, and one that points an operator at the wrong
     * process. What says the pass was partial is the count returned beside it.
     */
    public function test_a_partial_pass_still_reports_that_the_sweeper_ran(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->deliver($this->heartbeats(1));

        DB::table('seat_state')->where('seat_ref', $this->seatRef)
            ->update(['fold_cursor_received_at' => null]);

        $this->advanceServerClock(61);

        $result = $this->sweep();

        $this->assertSame(1, $result->failed, 'the only seat in the fleet is the poisoned one');
        $this->assertNotNull(
            DB::table('plane_state')->where('name', 'sweep_last_run_at')->value('at'),
            'the pass ran; a missing stamp would say the sweeper is dead, which is a different claim',
        );
    }

    /**
     * THE DISCRIMINATING CONTROL. Every assertion above is about a pass that failed a seat, and a
     * `pass()` that reported `failed` unconditionally would satisfy all of them. An ordinary fleet
     * must report ZERO failures — otherwise the count is decoration and the `partial()` surface
     * would fire on every healthy pass, which is § 5's own "trained away" argument one layer over.
     */
    public function test_an_ordinary_pass_reports_no_failures_at_all(): void
    {
        [$bToken] = $this->issueToken('aimla', 'desk-b');

        $this->deliver($this->cleanTurn());
        $this->deliver($this->cleanTurn('b-session'), token: $bToken, seat: 'desk-b');
        $this->fold();

        $this->advanceServerClock(1_000);

        $result = $this->sweep();

        $this->assertSame(2, $result->seats);
        $this->assertSame(0, $result->failed);
        $this->assertFalse($result->partial());
        $this->assertSame(0, $this->counter('sweep_seat_error'));
    }
}

<?php

namespace Tests\Feature\Fold;

use App\Fold\SeatFacts;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-17 — dedup, and the derived state after a re-delivery.
 *
 * ⚠ SCOPE. § 11's test has two halves. The DEDUP half — "the re-delivery returns `202` with
 * `duplicates` equal to the batch size and `accepted: 0`" — belongs to the ingest and is already
 * asserted by card #7338's own suite; it is re-asserted here only as the precondition of the half
 * this card owns. The RED — "set event retention to 7 days, purge, then re-deliver an 8-day-old
 * event" — needs `mezzanine:purge` (§ 2.1's fourth process), which neither half of card #7339
 * builds. Named in the PR body.
 *
 * What this card owes is the second half: THE DERIVED STATE IS BYTE-IDENTICAL BEFORE AND AFTER,
 * "assert the RENDERED OBJECT, not just the counts — a double-applied `tool.start` shows up as a
 * phantom open call".
 */
class At17DedupTest extends FoldTestCase
{
    public function test_a_verbatim_re_delivery_changes_neither_the_store_nor_the_derived_state(): void
    {
        $events = $this->clearKill();
        $envelope = ['batch_id' => $this->ulid()];

        $this->deliver($events, $envelope);
        $this->fold();

        $before = SeatFacts::versionBearing($this->seatRef);
        $storedEvents = DB::table('events')->where('seat_ref', $this->seatRef)->count();
        $version = (int) $this->state()->state_version;

        // The IDENTICAL batch, re-sent — D1 § 10.3's ambiguous-timeout retry, which "must be able
        // to converge without operator involvement".
        $this->deliver($events, $envelope);
        $this->fold();

        $this->assertSame($storedEvents, DB::table('events')->where('seat_ref', $this->seatRef)->count(),
            'the re-delivery inserted rows — `uq_dedup` is not holding');

        // ⛔ THE RENDERED OBJECT, NOT THE COUNTS. A phantom open call would move `open_calls`,
        // `action` and `render_state` without moving the event count at all.
        $this->assertEquals($before, SeatFacts::versionBearing($this->seatRef));
        $this->assertSame(0, (int) $this->state()->open_calls);
        $this->assertSame(2, DB::table('calls')->where('seat_ref', $this->seatRef)->count());

        // And nothing version-bearing moved, so no delta would be emitted for a batch that told
        // the server nothing it did not already know.
        $this->assertSame($version, (int) $this->state()->state_version);
    }

    public function test_the_discriminating_control_a_different_batch_does_move_the_state(): void
    {
        // Without this, a `versionBearing()` that always returned the same value would pass the
        // test above while measuring nothing.
        $this->deliver($this->clearKill());
        $this->fold();

        $before = SeatFacts::versionBearing($this->seatRef);

        // On the session `clear_kill` STARTED, not the one it ended — a `turn.start` addressed to
        // an already-ended session leaves `T` false (§ 4.3 reads `turn_open` only on sessions with
        // no `ended_at`), which is the safe reading and not the one this control is about.
        $this->deliver([$this->event(
            'turn.start', ['prompt_chars' => 7], 'b8e3d029-5c11-4f88-9a0d-3e72d5c9b024',
        )]);
        $this->fold();

        $this->assertNotEquals($before, SeatFacts::versionBearing($this->seatRef));
        $this->assertSame('working', $this->state()->activity_state);
    }
}

<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-22 — concurrent ingest cannot strand an event behind the cursor: the single-connection half.
 *
 * The overlapping same-seat ingest itself — transaction 1 held open across transaction 2 and a fold
 * pass — needs two real transactions, which the suite's one in-process connection cannot hold. It is
 * driven on committed rows across named connections in `At22LockFirstIngestTest` (card#9398), together
 * with the position of the ingest's seat lock that closes it.
 *
 * What is driven HERE, on the suite's own connection:
 *   · the control — a fresh batch, never aged, folds on the very next pass, because the fold reads
 *     `events` by id and has no age to wait out;
 *   · the purged-window branch's discriminator and its guarded write, INCLUDING the interleaving,
 *     by moving the head between the emptiness proof and the write through the seam the branch
 *     exposes for exactly that reason.
 *
 * Two fold workers partitioning the claim (`FOR UPDATE SKIP LOCKED`) is driven by neither file.
 */
class At22CursorSafetyTest extends FoldTestCase
{
    public function test_a_fresh_event_folds_on_the_next_pass(): void
    {
        // Delivered WITHOUT the harness's usual ageing, so the batch's `received_at` is `now`. The
        // fold reads `events` by id alone (card#9398): the seat lock the ingest takes first is what
        // keeps an uncommitted lower id from existing behind a committed higher one, so there is no
        // age to wait out and a fresh batch folds on the very next pass.
        $this->deliverFresh($this->cleanTurn());

        $head = (int) $this->state()->head_event_id;
        $this->assertGreaterThan(0, $head);

        app(Fold::class)->pass();

        $this->assertSame($head, (int) $this->state()->fold_cursor_event_id,
            'a committed event was held back from the fold');
        $this->assertSame(0, $this->counter('fold_window_purged'), 'a fresh window was mistaken for a purged one');
        $this->assertSame('idle', $this->state()->activity_state);
    }

    public function test_the_purged_window_branch_advances_the_cursor_instead_of_reclaiming_forever(): void
    {
        // § 11's discriminating control for the branch: "the same purged-window fixture with NO
        // concurrent ingest → the cursor advances to H on the first pass, `fold_window_purged` = 1,
        // and the seat LEAVES THE CLAIM, so the test is known to be capable of reporting 'the
        // branch did its job'."
        $this->deliver($this->cleanTurn());
        $this->fold();

        $head = (int) $this->state()->head_event_id;

        // The window above the cursor is purged out from under it — § 6.7's 14-day retention
        // outliving the fold's downtime — and the cursor is wound back below it, which is the state
        // a `rebuild --since` also leaves behind.
        DB::table('events')->where('seat_ref', $this->seatRef)->delete();
        DB::table('seat_state')->where('seat_ref', $this->seatRef)
            ->update(['fold_cursor_event_id' => 0]);

        $this->assertTrue($this->behind(), 'the fixture did not put the seat back on the claim');

        app(Fold::class)->pass();

        $this->assertSame($head, (int) $this->state()->fold_cursor_event_id);
        $this->assertSame(1, $this->counter('fold_window_purged'));
        $this->assertFalse($this->behind(), 'the seat re-claims every pass forever and never folds again');
    }

    public function test_an_ingest_interleaving_the_proof_and_the_write_strands_nothing(): void
    {
        // The hazard the branch's guard exists for, driven through the seam: an ordinary ingest
        // batch commits BETWEEN the emptiness proof and the cursor write, raising `head_event_id`.
        $this->deliver($this->cleanTurn());
        $this->fold();

        $purgedHead = (int) $this->state()->head_event_id;

        DB::table('events')->where('seat_ref', $this->seatRef)->delete();
        DB::table('seat_state')->where('seat_ref', $this->seatRef)
            ->update(['fold_cursor_event_id' => 0]);

        $interleaved = $this->cleanTurn();

        Fold::$afterEmptinessProof = function () use ($interleaved) {
            Fold::$afterEmptinessProof = null;      // once, at the window that matters

            $this->deliverFresh($interleaved);
        };

        app(Fold::class)->pass();

        // "`fold_window_purged` is +1 IN BOTH ARMS — it records the emptiness proof, which both
        // arms passed — with only the cursor differing."
        $this->assertSame(1, $this->counter('fold_window_purged'));

        // The head moved, so the guarded write matched NO ROW and nothing advanced. The alternative
        // — writing `head_event_id` re-read at write time — would have put the cursor on the head
        // of a batch this pass never folded, stranding every event of it while `fold_lag_ms` read 0
        // because the cursor was at the head. THAT SILENCE IS THE POINT.
        $this->assertSame(0, (int) $this->state()->fold_cursor_event_id,
            'the cursor jumped to a head the emptiness proof never covered');
        $this->assertGreaterThan($purgedHead, (int) $this->state()->head_event_id);

        // "In BOTH cases the next pass folds the interleaved batch and the seat's final state
        // equals a control run in which the same batch arrived after the purge branch completed."
        $this->advanceServerClock(Fold::VISIBILITY_LAG_S + 1);
        $this->fold();

        $this->assertSame('idle', $this->state()->activity_state);
        $this->assertFalse($this->behind());

        // ASSERT THE APPLIED EVENT SET, NOT JUST THE FINAL STATE — § 11 is explicit that "a state
        // that happens to match while an event was skipped is the failure this test exists to
        // catch". Every event of the interleaved batch is at or below the cursor.
        $unfolded = DB::table('events')->where('seat_ref', $this->seatRef)
            ->where('id', '>', (int) $this->state()->fold_cursor_event_id)->count();

        $this->assertSame(0, $unfolded, 'an event of the interleaved batch was stranded above the cursor');
        $this->assertSame(count($interleaved), DB::table('events')->where('seat_ref', $this->seatRef)->count());
    }

    /**
     * Deliver without moving the server clock — the batch's receipt is `now`.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function deliverFresh(array $events): void
    {
        $this->deliver($events, age: false);
    }
}

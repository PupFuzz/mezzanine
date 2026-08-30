<?php

namespace Tests\Feature\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * D1 § 10.3 (per-event dedup) and § 10.4 (batch-level idempotency).
 *
 * "The flusher retries on timeout, and a timeout is *ambiguous* — the server may well have
 * committed the batch. Retrying is correct; the duplicates it creates must be free."
 */
class IngestDedupTest extends IngestTestCase
{
    public function test_a_replayed_batch_returns_202_with_duplicates_and_stores_nothing_new(): void
    {
        $batch = $this->validBatch([$this->event(), $this->event()]);

        $this->postBatch($batch)->assertStatus(202)->assertJson(['accepted' => 2, 'duplicates' => 0]);

        // § 10.4: a repeat `batch_id` returns the previous response without re-processing.
        $this->postBatch($batch)->assertStatus(202)->assertJson(['accepted' => 2, 'duplicates' => 0]);

        $this->assertSame(2, $this->storedEvents());
        $this->assertSame(1, DB::table('batches')->count(), 'the replay was re-processed');
    }

    public function test_a_rebatched_retry_under_a_fresh_batch_id_still_deduplicates(): void
    {
        // § 10.4: per-event dedup "is the correctness mechanism, and it holds even when a retry is
        // re-batched under a fresh `batch_id`". The batch-id memory is "an optimisation, not the
        // correctness mechanism", so this is the path that must work when it misses.
        $events = [$this->event(), $this->event()];

        $this->postBatch($this->validBatch($events))->assertStatus(202)->assertJson(['accepted' => 2]);

        // Same events, new envelope — exactly what the flusher produces after a restart.
        $this->postBatch($this->validBatch($events))
            ->assertStatus(202)
            ->assertJson(['accepted' => 0, 'duplicates' => 2]);

        $this->assertSame(2, $this->storedEvents());
        $this->assertSame(2, DB::table('batches')->count());
    }

    public function test_a_partial_overlap_counts_both_halves(): void
    {
        $old = $this->event();
        $new = $this->event();

        $this->postBatch($this->validBatch([$old]))->assertStatus(202);

        $this->postBatch($this->validBatch([$old, $new]))
            ->assertStatus(202)
            ->assertJson(['accepted' => 1, 'duplicates' => 1]);

        $this->assertSame(2, $this->storedEvents());
        $this->assertSame(1, $this->seatCounter('duplicates'));
    }

    public function test_duplicates_never_trigger_the_rejection_path(): void
    {
        // § 12.4 ends with this in terms: "Duplicates are not a validation failure either, and
        // never trigger this path." A server that treated a repeat `event_id` as invalid would
        // turn every ambiguous-timeout retry into a permanent quarantine.
        $event = $this->event();

        $this->postBatch($this->validBatch([$event]))->assertStatus(202);

        for ($i = 0; $i < 5; $i++) {
            $this->postBatch($this->validBatch([$event]))->assertStatus(202);
        }

        $this->assertSame(0, $this->seatCounter('batches_refused.invalid_event'));
        $this->assertSame(1, $this->storedEvents());
    }

    public function test_dedup_is_scoped_to_the_seat(): void
    {
        // D2-MUST #3 keys dedup on `(install_id, seat_id, event_id)` — `(seat_ref, event_id)`
        // through the surrogate. A globally-unique constraint would make one seat's ULID
        // collision silently swallow another seat's event; ULIDs make that vanishingly unlikely,
        // which is precisely why a wrong scope would never be noticed.
        [$otherToken, $otherSeatRef] = $this->issueToken('aimla', 'aimla-impl-2');

        $sharedId = $this->ulid();

        $this->postBatch($this->validBatch([$this->event(['event_id' => $sharedId])]))
            ->assertStatus(202)->assertJson(['accepted' => 1]);

        $this->postBatch($this->validBatch(
            [$this->event(['event_id' => $sharedId, 'seat_id' => 'aimla-impl-2'])],
            ['seat_id' => 'aimla-impl-2'],
        ), token: $otherToken)->assertStatus(202)->assertJson(['accepted' => 1, 'duplicates' => 0]);

        $this->assertSame(1, $this->storedEvents());
        $this->assertSame(1, $this->storedEvents($otherSeatRef));
    }

    public function test_a_batch_id_replayed_outside_the_24h_window_is_answered_as_a_fresh_batch(): void
    {
        // D2 § 6.4 spells this one out because it is where the non-unique index earns its keep:
        // "a unique key on (seat_ref, batch_id) would reject the second row for the full 14 days
        // of retention, so 'answered as a fresh batch' would raise instead of answering."
        $batch = $this->validBatch([$this->event()]);

        $this->postBatch($batch)->assertStatus(202)->assertJson(['accepted' => 1]);

        $this->travel(25)->hours();

        $this->postBatch($batch)
            ->assertStatus(202)
            ->assertJson(['accepted' => 0, 'duplicates' => 1]);

        // Two `batches` rows for one `batch_id`, which is what the non-unique index permits, and
        // the per-event dedup is what kept it correct.
        $this->assertSame(2, DB::table('batches')->where('batch_id', $batch['batch_id'])->count());
        $this->assertSame(1, $this->storedEvents());
    }
}

<?php

namespace Tests\Feature\Ingest;

use App\Ingest\Wire;
use Illuminate\Support\Facades\DB;

/**
 * AT-13 — atomic batch rejection.
 *
 *   Build: a batch of 200 events where event 137 has `data` exceeding 3 KiB.
 *   GREEN: `422 invalid_event` with `index: 137` and the offending `field`; 0 of 200 stored; the
 *          batch is quarantined and never retried; `events_rejected_dropped` rises by 200 and the
 *          seat badges `lossy`; the stream continues with the next batch.
 *   RED:   allow partial ingest → 199 stored under a success status and the reporter's cursor
 *          advances, permanently losing event 137 with no record that anything was lost.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHICH HALF OF AT-13's GREEN IS THIS CARD'S, stated so the other half is not silently claimed.
 * `events_rejected_dropped`, the `lossy` badge, `REJECTED.txt` and "never retried" are all
 * REPORTER-side (D1 § 9.3, § 11.5) and belong to `fleet-reporter`. They are exercised for real —
 * against this endpoint, by the real flusher — in `tests/roundtrip/ingest-roundtrip.py`, which
 * drives this same 200-event batch and reads the reporter's own quarantine afterwards. What is
 * asserted HERE is the server half: the status, the `index`, and 0 of 200 stored.
 *
 * THE RED IS DEMONSTRATED BY REMOVING THE PRE-VALIDATION LOOP in `IngestPipeline` — see the PR
 * round for the diff and its output. `test_the_batch_is_refused_before_any_row_is_written` is
 * what turns that red: it is not a rollback assertion but a control-flow one, because a rollback
 * that has to work is a rollback that can fail.
 */
class At13AtomicBatchRejectionTest extends IngestTestCase
{
    private const BAD_INDEX = 137;

    /**
     * @return list<array<string, mixed>>
     */
    private function twoHundredWithABadOne(): array
    {
        $events = [];

        for ($i = 0; $i < 200; $i++) {
            $events[] = $this->event([
                'seq' => 48000 + $i,
                'data' => $i === self::BAD_INDEX
                    // Over the 3 KiB `data` cap of D1 § 4.3 / § 12.1 step 9. A real reporter
                    // cannot produce this — § 6.0 rule 5 clamps every bound before the write —
                    // which is exactly why § 12.1 says a 422 here "means a genuine reporter bug".
                    ? ['prompt_chars' => 1, 'project_label' => str_repeat('x', 3200)]
                    : ['prompt_chars' => 412, 'project_label' => 'mezzanine'],
            ]);
        }

        return $events;
    }

    public function test_event_137_refuses_the_whole_batch_with_its_index_and_field(): void
    {
        $batch = $this->validBatch($this->twoHundredWithABadOne());

        $this->postBatch($batch)
            ->assertStatus(422)
            ->assertJson([
                'error' => 'invalid_event',
                'index' => self::BAD_INDEX,
                'field' => 'data',
                'batch_id' => $batch['batch_id'],
            ]);
    }

    public function test_zero_of_two_hundred_are_stored(): void
    {
        $this->postBatch($this->validBatch($this->twoHundredWithABadOne()))->assertStatus(422);

        // Not "199" and not "some": § 12.4's first reason is that "a partial ingest under a
        // success status is indistinguishable from a full one, and the reporter deletes its
        // spool on success", so the number that matters is exactly zero.
        $this->assertSame(0, $this->storedEvents());
    }

    public function test_the_batch_is_refused_before_any_row_is_written(): void
    {
        // THE CONTROL-FLOW ASSERTION, and the one the RED turns on. § 12.4's atomicity is a
        // property of validating every event before writing any — not of a transaction that
        // rolls back. Asserted by counting queries: a refused batch must issue no INSERT at all,
        // so a partial-ingest implementation fails here even if its rollback happens to work.
        DB::enableQueryLog();

        $this->postBatch($this->validBatch($this->twoHundredWithABadOne()))->assertStatus(422);

        $writes = array_filter(
            DB::getQueryLog(),
            fn ($q) => str_contains(strtolower($q['query']), 'insert into "events"')
                || str_contains(strtolower($q['query']), 'insert into "batches"'),
        );

        DB::disableQueryLog();

        $this->assertSame([], $writes, 'a refused batch issued a write');
    }

    public function test_the_refusal_is_counted_against_the_seat(): void
    {
        $this->postBatch($this->validBatch($this->twoHundredWithABadOne()))->assertStatus(422);

        // § 12.7 `batches_refused.<error>`, keyed by error code, counted against the token's
        // binding. This is the server's half of "nothing is discarded uncounted".
        $this->assertSame(1, $this->seatCounter('batches_refused.invalid_event'));
    }

    public function test_the_stream_continues_with_the_next_batch(): void
    {
        $this->postBatch($this->validBatch($this->twoHundredWithABadOne()))->assertStatus(422);

        // AT-13's last GREEN clause. § 12.4's cost — "one malformed event costs its ≤ 199
        // neighbours" — is bounded by the poison-pill rule, which "stops one bad batch from
        // wedging the stream". A server that had recorded anything durable about the refusal
        // (a rate-limit lockout, a poisoned dedup entry, a half-written batch row) would fail
        // here rather than at the assertion above.
        $good = $this->validBatch([$this->event(['seq' => 49000])]);

        $this->postBatch($good)->assertStatus(202)->assertJson(['accepted' => 1]);
        $this->assertSame(1, $this->storedEvents());
    }

    public function test_an_oversize_data_field_is_measured_the_way_the_producer_measures_it(): void
    {
        // The 3 KiB cap is what refused event 137, so how it is MEASURED is part of this test's
        // subject. D1 § 6.14: every cap in the document is measured on the `JSON.stringify` form.
        // PHP's `json_encode` defaults escape `/` as `\/`, which would inflate a `data` full of
        // file paths — `descriptor` is a sanitized command line — and refuse a batch the producer
        // measured as in-bounds, taking its 199 neighbours with it. This is the fixture that
        // would have caught that: 1,200 slashes are 1,200 bytes to `JSON.stringify` and 2,400 to
        // PHP's default, and the payload sits between the two — 2,478 bytes measured D1's way
        // and 3,678 measured PHP's, either side of the 3,072-byte cap.
        //
        // (`descriptor`'s own 200 B bound is NOT what is being tested and is NOT enforced here:
        // per-field bounds are the reporter's clamp under § 6.0 rule 5, and `KindRegistry`
        // records why the ingest does not re-impose them. The subject is § 12.1 step 9's `data`
        // cap, which is the one bound the ingest does enforce.)
        $descriptor = str_repeat('/a', 1200);

        $data = ['call_id' => $this->ulid(), 'tool_name' => 'Bash', 'descriptor' => $descriptor];

        $this->assertGreaterThan(3072, strlen(json_encode($data)), 'the fixture no longer discriminates');
        $this->assertLessThan(3072, strlen(Wire::serialize($data)), 'the fixture no longer discriminates');

        $this->postBatch($this->validBatch([$this->event(['kind' => 'tool.start', 'data' => $data])]))
            ->assertStatus(202);
    }
}

<?php

namespace Tests\Feature\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * AT-12 — out-of-order batches.
 *
 *   Build: capture two consecutive batches; deliver batch 2, then batch 1.
 *   GREEN: the final ledger and derived state are identical to in-order delivery, including a
 *          `tool.end` that arrives before its `tool.start` (created closed, not reopened by the
 *          late start).
 *   RED:   apply state by arrival order → a completed call reopens and the seat stays "working"
 *          forever.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHERE THIS CARD'S HALF STOPS, AND WHY THE OBVIOUS TEST WOULD BE A DECORATION.
 *
 * "The final ledger and derived state" are the fold's — card #7339. The naive ingest-side test
 * ("post them backwards, assert the rows match") CANNOT FAIL: an ingest that only appends rows
 * produces the same rows in either order by construction, so it would report that the test ran.
 *
 * The ingest's actual obligation is narrower and is a real one: it must leave the fold ABLE to
 * reach AT-12's GREEN. D2-MUST #4 pins the ordering key to `(event_time, seq_epoch, seq)`, "never
 * by arrival order", so the ingest owes three things, each of which a plausible edit breaks and
 * each of which is asserted below with the edit named:
 *
 *   1. `event_time` is stored as the SEAT said it, never replaced by `received_at`. The plausible
 *      edit is "normalise the clock at the boundary"; it collapses the ordering key onto arrival
 *      order and AT-12's RED becomes unavoidable downstream.
 *   2. A late batch is ACCEPTED, not refused. The plausible edit is a monotonic-`seq` guard —
 *      which under § 12.4 and § 11.5 would permanently quarantine every batch that arrived out of
 *      order, i.e. every retry after an ambiguous timeout.
 *   3. No arrival-ordered watermark is written to `seat_state`, so no counter derived from one can
 *      invent a gap. See `IngestHappyPathTest::test_the_ingest_does_not_write_the_columns_card_7339_owns`.
 *
 * The comparison against in-order delivery is kept as well — it is weak alone, but it is what
 * makes 1 and 2 an end-to-end claim rather than three separate column assertions.
 */
class At12OutOfOrderBatchesTest extends IngestTestCase
{
    /**
     * Two consecutive batches from one seat: batch 1 opens a call, batch 2 closes it. Delivered
     * backwards, the `tool.end` arrives before its `tool.start`.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function twoConsecutiveBatches(): array
    {
        $callId = $this->ulid();

        $one = $this->validBatch([
            $this->event([
                'kind' => 'tool.start',
                'event_time' => '2026-08-23T14:07:01.771Z',
                'seq' => 48210,
                'data' => [
                    'call_id' => $callId, 'tool_name' => 'Grep',
                    'descriptor' => 'Grep: schema_version', 'descriptor_truncated' => false,
                    'agent_scope' => 'main', 'parent_call_id' => null,
                    'harness_call_ref' => 'toolu_01A9F3kQ2mZ', 'open_calls_before' => 0,
                ],
            ]),
        ]);

        $two = $this->validBatch([
            $this->event([
                'kind' => 'tool.end',
                'event_time' => '2026-08-23T14:07:03.118Z',
                'seq' => 48211,
                'data' => [
                    'call_id' => $callId, 'tool_name' => 'Grep', 'outcome' => 'completed',
                    'abort_reason' => null, 'duration_ms' => 1347,
                    'duration_source' => 'harness', 'close_source' => 'post_tool_use',
                    'match' => 'harness_ref',
                ],
            ]),
        ]);

        return [$one, $two];
    }

    public function test_a_late_batch_is_accepted_not_refused(): void
    {
        // OBLIGATION 2. A monotonic-`seq` guard is the plausible edit that breaks this, and its
        // cost is not a 400 — under § 12.4 the whole batch dies, and under § 11.5's poison-pill
        // rule it is quarantined and never retried. Out-of-order delivery is not an edge case:
        // § 10.3 makes retry-after-ambiguous-timeout the normal path, and § 11.2 makes the
        // flusher split batches at version boundaries.
        [$one, $two] = $this->twoConsecutiveBatches();

        $this->postBatch($two)->assertStatus(202)->assertJson(['accepted' => 1]);
        $this->postBatch($one)->assertStatus(202)->assertJson(['accepted' => 1]);

        $this->assertSame(2, $this->storedEvents());
    }

    public function test_the_ordering_key_survives_backwards_delivery(): void
    {
        // OBLIGATION 1, and the assertion the `event_time := received_at` edit reds. Ordering by
        // the D2-MUST #4 key must put the `tool.start` first even though it ARRIVED second; the
        // `id` column — arrival order, and the fold's cursor — must put it second. The two
        // disagreeing is the whole point: an ingest that rewrote `event_time` would make them
        // agree, and the fold would have nothing left to order by but arrival.
        [$one, $two] = $this->twoConsecutiveBatches();

        $this->postBatch($two)->assertStatus(202);
        $this->postBatch($one)->assertStatus(202);

        $byOrderingKey = DB::table('events')
            ->orderBy('event_time')->orderBy('seq_epoch')->orderBy('seq')
            ->pluck('kind')->all();

        $byArrival = DB::table('events')->orderBy('id')->pluck('kind')->all();

        $this->assertSame(['tool.start', 'tool.end'], $byOrderingKey);
        $this->assertSame(['tool.end', 'tool.start'], $byArrival);
    }

    public function test_backwards_delivery_leaves_the_same_durable_state_as_in_order_delivery(): void
    {
        [$one, $two] = $this->twoConsecutiveBatches();

        $this->postBatch($one)->assertStatus(202);
        $this->postBatch($two)->assertStatus(202);

        $inOrder = $this->orderedFacts();

        // A second seat, same two batches, delivered backwards.
        [$otherToken, $otherSeatRef] = $this->issueToken('aimla', 'aimla-impl-2');

        $rebind = function (array $batch) {
            $batch['seat_id'] = 'aimla-impl-2';
            $batch['events'] = array_map(function (array $e) {
                $e['seat_id'] = 'aimla-impl-2';

                return $e;
            }, $batch['events']);

            return $batch;
        };

        $this->postBatch($rebind($two), token: $otherToken)->assertStatus(202);
        $this->postBatch($rebind($one), token: $otherToken)->assertStatus(202);

        $outOfOrder = $this->orderedFacts($otherSeatRef);

        // Everything the fold reads is identical. What is deliberately NOT compared is `id` and
        // `received_at` — those ARE arrival order, they are supposed to differ, and a test that
        // demanded they match would be demanding the ingest lie about when it received something.
        $this->assertSame($inOrder, $outOfOrder);
    }

    public function test_a_late_batch_does_not_drag_the_head_backwards(): void
    {
        // `head_event_id` is the fold's "how far is there to go" bound (D2 § 2.3). It is
        // `MAX(events.id)` — assignment order — so a late batch RAISES it like any other. An
        // implementation that derived it from `seq` would move it backwards here and the fold
        // would stop claiming the seat, which reads as a frozen fold on a healthy one.
        [$one, $two] = $this->twoConsecutiveBatches();

        $this->postBatch($two)->assertStatus(202);
        $afterLate = (int) DB::table('seat_state')->where('seat_ref', $this->seatRef)->value('head_event_id');

        $this->postBatch($one)->assertStatus(202);
        $afterEarly = (int) DB::table('seat_state')->where('seat_ref', $this->seatRef)->value('head_event_id');

        $this->assertGreaterThan($afterLate, $afterEarly);
    }

    /**
     * Every column the fold orders or projects from, in the D2-MUST #4 key's order.
     *
     * @return list<array<string, mixed>>
     */
    private function orderedFacts(?int $seatRef = null): array
    {
        return DB::table('events')
            ->where('seat_ref', $seatRef ?? $this->seatRef)
            ->orderBy('event_time')->orderBy('seq_epoch')->orderBy('seq')
            ->get(['event_id', 'schema_version', 'kind', 'event_time', 'seq_epoch', 'seq', 'session_id', 'oversize', 'data'])
            ->map(fn ($r) => (array) $r)
            ->all();
    }
}

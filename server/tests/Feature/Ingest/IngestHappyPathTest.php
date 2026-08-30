<?php

namespace Tests\Feature\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * The `202` path, the § 4.6 response body, and the seat_state columns the ingest writes.
 */
class IngestHappyPathTest extends IngestTestCase
{
    public function test_a_valid_batch_is_accepted_with_the_section_4_6_body(): void
    {
        $batch = $this->validBatch();

        $response = $this->postBatch($batch);

        $response->assertStatus(202)
            ->assertJson([
                'batch_id' => $batch['batch_id'],
                'accepted' => 1,
                'duplicates' => 0,
                'ignored_unknown_kinds' => 0,
                'coerced_enum_values' => 0,
            ]);

        // § 4.6's body has exactly these six keys. `ignored_unknown_fields` is a § 12.7 counter
        // and is deliberately NOT here: adding it would be a wire change with no version bump
        // behind it, on the one response the whole fleet parses.
        $this->assertSame([
            'batch_id', 'accepted', 'duplicates', 'ignored_unknown_kinds',
            'coerced_enum_values', 'server_time',
        ], array_keys($response->json()));

        $this->assertSame(1, $this->storedEvents());
    }

    public function test_the_stored_event_keeps_the_seat_clock_and_the_ordering_key_verbatim(): void
    {
        $this->postBatch($this->validBatch([
            $this->event(['event_time' => '2026-08-23T14:06:58.004Z', 'seq' => 48209]),
        ]))->assertStatus(202);

        $row = DB::table('events')->where('seat_ref', $this->seatRef)->first();

        // D1 § 12.5 / D2-MUST #4: `event_time` is the seat clock, stored verbatim and never
        // rewritten, and `(event_time, seq_epoch, seq)` is the ordering key. `received_at` is a
        // SEPARATE column, not a replacement for it.
        $this->assertStringStartsWith('2026-08-23 14:06:58.004', (string) $row->event_time);
        $this->assertSame('01K3T0000A5N7M2X9V4B6D0FGH', $row->seq_epoch);
        $this->assertSame(48209, (int) $row->seq);
        $this->assertNotSame((string) $row->event_time, (string) $row->received_at);
    }

    public function test_the_ingest_writes_head_receipt_and_skew_and_seeds_the_fold_cursor_once(): void
    {
        $before = DB::table('seat_state')->where('seat_ref', $this->seatRef)->first();

        // The provisioned-but-silent state D2 § 6.4's comment describes.
        $this->assertSame(0, (int) $before->head_event_id);
        $this->assertNull($before->fold_cursor_received_at);
        $this->assertNull($before->last_receipt_at);
        $this->assertSame('offline', $before->render_state);
        $this->assertSame('no_data_yet', $before->unknown_reason);

        $this->postBatch($this->validBatch())->assertStatus(202);

        $first = DB::table('seat_state')->where('seat_ref', $this->seatRef)->first();

        $this->assertGreaterThan(0, (int) $first->head_event_id);
        $this->assertNotNull($first->fold_cursor_received_at);
        $this->assertNotNull($first->last_receipt_at);
        $this->assertNotNull($first->clock_skew_ms);

        $seed = $first->fold_cursor_received_at;

        // D2 § 2.3: the seed is ONE-SHOT — "only where `fold_cursor_received_at` is still
        // `NULL`". A second write would drag `fold_lag_ms` back to zero on every batch, which is
        // the frozen-fold instrument silently disabling itself.
        $this->travel(2)->seconds();
        $this->postBatch($this->validBatch())->assertStatus(202);

        $second = DB::table('seat_state')->where('seat_ref', $this->seatRef)->first();

        $this->assertSame($seed, $second->fold_cursor_received_at);
        $this->assertGreaterThan((int) $first->head_event_id, (int) $second->head_event_id);
        $this->assertNotSame($first->last_receipt_at, $second->last_receipt_at);
    }

    public function test_the_ingest_does_not_write_the_columns_card_7339_owns(): void
    {
        // Not a tidiness assertion. `last_event_seq` advanced on ARRIVAL order would move
        // backwards on a late batch, and `seq_gap` derived from it would report a gap that
        // arrival order invented — AT-12's defect, one column over. `state_version` bumped here
        // would mint a feed delta for a heartbeat, which D2 § 6.4 excludes by construction.
        $this->postBatch($this->validBatch())->assertStatus(202);

        $row = DB::table('seat_state')->where('seat_ref', $this->seatRef)->first();

        $this->assertNull($row->last_event_seq);
        $this->assertNull($row->last_event_seq_epoch);
        $this->assertSame(0, (int) $row->state_version);
        $this->assertSame(0, (int) $row->fold_cursor_event_id);
        $this->assertNull($row->last_activity_event_time);
        $this->assertNull($row->last_activity_kind);
    }

    public function test_the_batch_row_records_the_per_batch_counters_and_the_skew_gauge(): void
    {
        $this->postBatch($this->validBatch([$this->event(), $this->event()]))->assertStatus(202);

        $row = DB::table('batches')->where('seat_ref', $this->seatRef)->first();

        $this->assertSame(2, (int) $row->event_count);
        $this->assertSame(2, (int) $row->accepted);
        $this->assertSame(0, (int) $row->duplicates);
        $this->assertSame(202, (int) $row->response_status);
        $this->assertSame('linux', $row->reporter_platform);

        // `sent_at` in the fixture is in the past, so the server clock is ahead: a positive skew.
        $this->assertGreaterThan(0, (int) $row->clock_skew_ms);
    }

    public function test_health_reports_the_one_declared_accepted_set(): void
    {
        $this->call('GET', '/api/ingest/health', server: $this->serverHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ]))->assertOk()->assertJson([
            'accepted_schema_versions' => [1],
            'min_reporter_version' => '0.1.0',
        ]);
    }

    public function test_health_refuses_without_a_token(): void
    {
        $this->call('GET', '/api/ingest/health', server: $this->serverHeaders([]))
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);
    }
}

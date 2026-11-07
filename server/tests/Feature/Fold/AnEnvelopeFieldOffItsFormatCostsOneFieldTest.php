<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;

/**
 * card#11572 — a batch-envelope string that breaks its column's format costs that one field, never
 * the batch.
 *
 * `batches.reporter_version` and `batches.runtime_version` are `CHARACTER SET ascii` columns
 * (D2 § 6.4), and the envelope check at step 8 holds them to D1 § 4.2's byte bound only. A value
 * inside the bound that is not ASCII used to fail the `batches` INSERT under the store's strict mode:
 * `503 server_error, store_failed`, which § 11.5 makes retryable, on a batch the reporter re-sends
 * unchanged — and every batch from that seat carries the same version, so the seat went dark for
 * good. The operator's D18 (2026-09-13, card#9346), applied one plane up from the fold: the ingest
 * stores `null` for the field, counts `format_refused.<field>` (D2 § 7.2), accepts the batch and its
 * events fold. A `422` refusal was the declined option, so these batches are now accepted where
 * before they were not, and no batch accepted before is refused now.
 *
 * Every case drives the real ingest and the real fold on MariaDB. The population is every `batches`
 * column fed from a wire string; `BatchValidator`'s docblock names it.
 */
class AnEnvelopeFieldOffItsFormatCostsOneFieldTest extends FoldTestCase
{
    /** `é` is two bytes of UTF-8 and no byte of ASCII: under the 24-byte bound, outside the column. */
    private const NON_ASCII = 'é';

    /** The card's own case: a non-ASCII `reporter_version` is accepted and its events fold. */
    public function test_a_non_ascii_reporter_version_costs_the_version_and_not_the_batch(): void
    {
        $batchId = $this->ulid();

        $this->deliver($this->heartbeats(1), ['batch_id' => $batchId, 'reporter_version' => '0.5.0'.self::NON_ASCII]);
        $this->fold();

        $row = $this->batchRow($batchId);
        $this->assertNull($row->reporter_version);
        $this->assertSame('v22.11.0', $row->runtime_version, 'the rest of the envelope did not land');
        $this->assertNull($this->state()->reporter_version, 'the fold did not carry the batch\'s null onto the seat');
        $this->assertFolded();
        $this->assertCounted(['reporter_version' => 1, 'runtime_version' => 0]);
    }

    public function test_a_non_ascii_runtime_version_costs_the_runtime_and_not_the_batch(): void
    {
        $batchId = $this->ulid();

        $this->deliver($this->heartbeats(1), ['batch_id' => $batchId, 'runtime_version' => 'v22.11.0'.self::NON_ASCII]);
        $this->fold();

        $row = $this->batchRow($batchId);
        $this->assertNull($row->runtime_version);
        $this->assertNotNull($row->reporter_version, 'the rest of the envelope did not land');
        $this->assertNotNull($this->state()->reporter_version);
        $this->assertFolded();
        $this->assertCounted(['reporter_version' => 0, 'runtime_version' => 1]);
    }

    /** Both off format: each field is nulled and counted once, and the batch still lands. */
    public function test_both_versions_off_format_are_each_counted_once(): void
    {
        $batchId = $this->ulid();

        $this->deliver($this->heartbeats(1), [
            'batch_id' => $batchId,
            'reporter_version' => self::NON_ASCII,
            'runtime_version' => self::NON_ASCII,
        ]);
        $this->fold();

        $row = $this->batchRow($batchId);
        $this->assertNull($row->reporter_version);
        $this->assertNull($row->runtime_version);
        $this->assertFolded();
        $this->assertCounted(['reporter_version' => 1, 'runtime_version' => 1]);
    }

    /**
     * The count is per ACCEPTED batch. A re-send of the same `batch_id` inside D1 § 10.4's 24 h is
     * answered from the first acceptance and counts nothing more; the batch is stored once.
     */
    public function test_a_resent_batch_is_counted_once(): void
    {
        $batchId = $this->ulid();
        $events = $this->heartbeats(1);
        $envelope = ['batch_id' => $batchId, 'reporter_version' => '0.5.0'.self::NON_ASCII];

        $this->deliver($events, $envelope);
        $this->deliver($events, $envelope);
        $this->fold();

        $this->assertSame(1, DB::table('batches')->where('batch_id', $batchId)->count());
        $this->assertCounted(['reporter_version' => 1, 'runtime_version' => 0]);
    }

    /**
     * THE CHECK IS THE COLUMN'S FORMAT, NOT SEMVER. D1 § 4.2 types `reporter_version` as semver, and
     * the server deliberately stores an ASCII value off that pattern verbatim: it is the value
     * `update_required` badges as a build that is not current (AT-D2-30's `0.3.0-dev`). Nulling it
     * would clear that badge. So an ASCII off-semver version is stored as sent and counts nothing.
     */
    public function test_an_ascii_version_off_semver_is_stored_verbatim_and_uncounted(): void
    {
        $batchId = $this->ulid();

        $this->deliver($this->heartbeats(1), ['batch_id' => $batchId, 'reporter_version' => '0.3.0-dev', 'runtime_version' => 'bun 1.1']);
        $this->fold();

        $row = $this->batchRow($batchId);
        $this->assertSame('0.3.0-dev', $row->reporter_version);
        $this->assertSame('bun 1.1', $row->runtime_version);
        $this->assertSame('0.3.0-dev', $this->state()->reporter_version);
        $this->assertFolded();
        $this->assertCounted(['reporter_version' => 0, 'runtime_version' => 0]);
    }

    private function batchRow(string $batchId): object
    {
        $row = DB::table('batches')->where('seat_ref', $this->seatRef)->where('batch_id', $batchId)->first();
        $this->assertNotNull($row, 'the batch was not stored');

        return $row;
    }

    /** The heartbeat the batch carried landed, and nothing was quarantined. */
    private function assertFolded(): void
    {
        $state = $this->state();
        $this->assertNotNull($state->last_heartbeat_received_at, 'the batch\'s heartbeat did not fold');
        $this->assertSame(86_213, (int) $state->reporter_uptime_s, 'the rest of the heartbeat did not land');
        $this->assertSame(0, (int) $state->fold_errors, 'an event was quarantined (§ 6.5\'s poison-event rule)');
        $this->assertSame(1, $this->counter('accepted'));
    }

    /** @param  array<string, int>  $expected */
    private function assertCounted(array $expected): void
    {
        foreach ($expected as $field => $count) {
            $this->assertSame($count, $this->counter('format_refused.'.$field), "`format_refused.{$field}` is not {$count}");
        }
    }
}

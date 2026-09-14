<?php

namespace Tests\Feature\Ingest;

use App\Ingest\BatchWriter;
use App\Ingest\ServerFault;
use App\Read\FleetHealth;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Fold\ConcurrencyError;

/**
 * card#9465 — a failure while the ingest writes is answered in D1 § 12.2's shape, counted, and never
 * acknowledged; `docs/design/FLEET-STATE.md § 2.2`'s ingest-write row.
 *
 * COUNTED AS A FAILURE, NEVER AS A REFUSAL (`assertCountedAsFailed()`). D1 § 12.7's
 * `batches_refused.<error>` is what the floor's `batches_rejected` drill-down reads and
 * `unattributed_refusals` is the fleet's refusal count, while the reporter retries every `5xx` and a
 * retry commits once. A fault in either would report a stored batch as refused.
 *
 * ⚠ THESE RUN AT TRANSACTION LEVEL 2, inside `RefreshDatabase`'s transaction, where Laravel rethrows a
 * concurrency error as `DeadlockException` — the nested shape. `IngestWriteBoundTest` drives the
 * top-level shape (`QueryException`) and a real server-ended transaction on committed connections.
 *
 * A concurrency error injected here does NOT roll back the savepoint: Laravel skips the rollback
 * because the engine, raising it for real, has already rolled back the whole transaction. The
 * contended cases therefore assert the answer and the counter, and leave "nothing stored" to the
 * committed rig, where the transaction is the top level and is rolled back.
 */
class IngestServerErrorTest extends IngestTestCase
{
    protected function tearDown(): void
    {
        BatchWriter::$afterFirstChunk = null;

        parent::tearDown();
    }

    /**
     * Store errors that are not concurrency errors: the engine's `max_statement_time` interrupt, and the
     * connection the server ended (the message a killed idle transaction's next statement raises).
     *
     * @return array<string, array{string}>
     */
    public static function storeFailures(): array
    {
        return [
            'statement interrupted' => ['SQLSTATE[70100]: <<Unknown error>>: 1969 Query execution was interrupted (max_statement_time exceeded)'],
            'connection ended' => ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'],
        ];
    }

    #[DataProvider('storeFailures')]
    public function test_a_store_failure_inside_the_write_is_a_counted_503_that_stores_nothing(string $engineMessage): void
    {
        Exceptions::fake();

        $batch = $this->validBatch([$this->event(), $this->event(['seq' => 48210])]);
        $before = DB::table('seat_state')->where('seat_ref', $this->seatRef)->first();
        $globals = $this->globalCounters();

        // After the first chunk: rows are inserted and must not survive.
        BatchWriter::$afterFirstChunk = function () use ($engineMessage) {
            throw self::storeError($engineMessage);
        };

        $response = $this->postBatch($batch);

        $this->assertServerError($response, 503, 'store_failed', $batch['batch_id'], $engineMessage);

        $this->assertSame(0, $this->storedEvents());
        $this->assertFalse(DB::table('batches')->where('batch_id', $batch['batch_id'])->exists());
        $after = DB::table('seat_state')->where('seat_ref', $this->seatRef)->first();
        $this->assertSame((int) $before->head_event_id, (int) $after->head_event_id);
        $this->assertSame($before->last_receipt_at, $after->last_receipt_at);

        $this->assertCountedAsFailed('store_failed', true, $globals);
        $this->assertSame(0, $this->seatCounter('accepted'));
        Exceptions::assertReported(QueryException::class);
    }

    public function test_the_retry_of_a_failed_batch_commits_it_exactly_once(): void
    {
        $batch = $this->validBatch([$this->event(), $this->event(['seq' => 48210])]);

        BatchWriter::$afterFirstChunk = function () {
            throw self::storeError('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        };

        $this->postBatch($batch)->assertStatus(503);

        BatchWriter::$afterFirstChunk = null;

        // The flusher's retry: the same bytes, the same batch_id (D1 § 11.5).
        $this->postBatch($batch)->assertStatus(202)->assertJson(['accepted' => 2, 'duplicates' => 0]);

        // And a second retry, as a flusher that missed the 202 sends: § 10.4's replay, not a second write.
        $this->postBatch($batch)->assertStatus(202)->assertJson(['accepted' => 2, 'duplicates' => 0]);

        $this->assertSame(2, $this->storedEvents());
        $this->assertSame(1, DB::table('batches')->where('batch_id', $batch['batch_id'])->count());
        $this->assertSame(2, $this->seatCounter('accepted'));
        $this->assertSame(1, $this->seatCounter('batches_failed.store_failed'));
        $this->assertSame(0, $this->seatRefusals());
    }

    /** @return array<string, array{int}> */
    public static function concurrencyErrors(): array
    {
        return ['1020 record changed' => [1020], '1205 lock wait timeout' => [1205], '1213 deadlock' => [1213]];
    }

    #[DataProvider('concurrencyErrors')]
    public function test_a_concurrency_error_is_a_store_error_answered_as_contention(int $errno): void
    {
        Exceptions::fake();

        $batch = $this->validBatch();
        $globals = $this->globalCounters();

        BatchWriter::$afterFirstChunk = function () use ($errno) {
            throw ConcurrencyError::raised($errno);
        };

        $response = $this->postBatch($batch);

        $this->assertServerError($response, 503, 'store_contended', $batch['batch_id'], ConcurrencyError::raised($errno)->getPrevious()->getMessage());
        $this->assertCountedAsFailed('store_contended', true, $globals);

        // The nested shape: what reached the classifier was Laravel's rethrow, not a QueryException.
        Exceptions::assertReported(DeadlockException::class);
    }

    public function test_a_programming_error_inside_the_write_is_a_counted_500_and_still_reaches_exception_reporting(): void
    {
        Exceptions::fake();

        $batch = $this->validBatch();
        $globals = $this->globalCounters();

        BatchWriter::$afterFirstChunk = function () {
            throw new \LogicException('a defect in the write, not the store');
        };

        $response = $this->postBatch($batch);

        $this->assertServerError($response, 500, 'internal', $batch['batch_id'], 'a defect in the write');
        $this->assertSame(0, $this->storedEvents());
        $this->assertCountedAsFailed('internal', true, $globals);
        Exceptions::assertReported(fn (\LogicException $e) => $e->getMessage() === 'a defect in the write, not the store');
    }

    public function test_a_store_failure_before_the_token_resolves_is_counted_globally_and_not_as_a_refusal(): void
    {
        Exceptions::fake();

        $batch = $this->validBatch();
        $globals = $this->globalCounters();

        DB::connection()->beforeExecuting(function (string $query) {
            if (str_contains($query, 'ingest_tokens')) {
                throw self::storeError('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
        });

        $response = $this->postBatch($batch);

        $this->assertServerError($response, 503, 'store_failed', $batch['batch_id'], 'gone away');

        // No identity was established, so no seat may be named (D1 § 12.1's attribution rule): the same
        // key, globally — and not `unattributed_refusals`, because nothing was refused.
        $this->assertCountedAsFailed('store_failed', false, $globals);
    }

    /**
     * The global rows a fault before step 4 is counted in are readable on the surface D2 § 7.1 names for
     * them, `GET /api/fleet/health`'s `counters`: one member per fault class, so a new class cannot be
     * counted into a row no surface reads.
     */
    public function test_every_fault_class_has_a_fleet_health_member(): void
    {
        foreach (ServerFault::cases() as $fault) {
            $this->assertContains('batches_failed.'.$fault->value, FleetHealth::COUNTERS, $fault->name);
        }
    }

    public function test_a_store_that_cannot_even_count_the_failure_still_answers_503(): void
    {
        Exceptions::fake();

        $batch = $this->validBatch();
        $globals = $this->globalCounters();
        $down = true;

        DB::connection()->beforeExecuting(function () use (&$down) {
            if ($down) {
                throw self::storeError('SQLSTATE[HY000] [2002] Connection refused');
            }
        });

        $response = $this->postBatch($batch);
        $down = false;

        $this->assertServerError($response, 503, 'store_failed', $batch['batch_id'], 'Connection refused');

        // Nothing could be counted, so the log is the only surface: both failures are reported.
        $this->assertSame($globals, $this->globalCounters());
        $this->assertSame(0, $this->seatCounter('batches_failed.store_failed'));
        Exceptions::assertReportedCount(2);
    }

    /**
     * D1 § 12.2's shape, exactly: `error`, `message`, `detail`, and the `batch_id` every refusal that
     * has one carries — and no internals in any of it.
     */
    private function assertServerError(TestResponse $response, int $status, string $detail, string $batchId, string $internals): void
    {
        $response->assertStatus($status);

        $body = $response->json();
        $this->assertSame(['error', 'message', 'detail', 'batch_id'], array_keys($body));
        $this->assertSame('server_error', $body['error']);
        $this->assertSame($detail, $body['detail']);
        $this->assertSame($batchId, $body['batch_id']);
        $this->assertIsString($body['message']);
        $this->assertNotSame('', $body['message']);
        $this->assertStringNotContainsString($internals, $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    /**
     * Exactly one counter for the fault, and not a refusal's: `batches_failed.<detail>` on the token's
     * binding when step 4 resolved one, the same key in `global_counters` when it did not — and no
     * `batches_refused.*` row on the seat, no `unattributed_refusals`, no other global moved.
     *
     * @param  array<string, int>  $globalsBefore
     */
    private function assertCountedAsFailed(string $detail, bool $attributed, array $globalsBefore): void
    {
        $name = 'batches_failed.'.$detail;

        $this->assertSame($attributed ? 1 : 0, $this->seatCounter($name), "the seat's {$name}");
        $this->assertSame(0, $this->seatRefusals(), 'a fault was counted as a refusal on the seat');

        $moved = [];

        foreach ($this->globalCounters() as $counter => $value) {
            if ($value !== ($globalsBefore[$counter] ?? 0)) {
                $moved[$counter] = $value - ($globalsBefore[$counter] ?? 0);
            }
        }

        $this->assertSame($attributed ? [] : [$name => 1], $moved, 'the global counters the fault moved');
    }

    /** Every `batches_refused.*` increment on this seat. */
    private function seatRefusals(): int
    {
        return (int) DB::table('seat_counters')->where('seat_ref', $this->seatRef)
            ->where('name', 'like', 'batches\\_refused.%')->sum('value');
    }

    /** @return array<string, int> */
    private function globalCounters(): array
    {
        return DB::table('global_counters')->pluck('value', 'name')->map(fn ($v) => (int) $v)->all();
    }

    private static function storeError(string $engineMessage): QueryException
    {
        return new QueryException('mysql', 'insert ignore into `events` (…) values (…)', [], new \PDOException($engineMessage));
    }
}

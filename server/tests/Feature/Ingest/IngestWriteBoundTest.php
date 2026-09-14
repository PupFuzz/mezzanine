<?php

namespace Tests\Feature\Ingest;

use App\Ingest\BatchWriter;
use App\Ingest\IngestPipeline;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Fold\CommittedSeatTestCase;
use Tests\Feature\Fold\ConcurrencyError;

/**
 * card#9465 — the server-side bound on an ingest transaction, and the store-failure answer at the depth
 * production runs the write, ON REAL CONNECTIONS WITH REAL COMMITS (`CommittedSeatTestCase`).
 *
 * The ingest takes the seat's `seat_state` row lock as its transaction's first statement
 * (`docs/design/FLEET-STATE.md § 6.5`), so an ingest whose application stops talking to the store
 * mid-transaction holds that seat's fold and ingest for as long as the server keeps the transaction.
 * `docs/design/FLEET-STATE.md § 2.2`'s ingest-write row states the bound and derives it; these tests
 * assert it is set on the ingest's own session and on no other, that the server really does end such a
 * transaction and free the lock, and that what the post then answers is D1 § 12.2's retryable `503`.
 *
 * Every post goes through the HTTP kernel on a named connection made the default (`on()`), because the
 * bound is pinned by `IngestPipeline` — the request, whose reporter's deadline the figure is derived
 * from — and not by `BatchWriter::write()`, which the rig's other tests call directly.
 */
class IngestWriteBoundTest extends CommittedSeatTestCase
{
    private string $token;

    protected function install(): string
    {
        return 'ingest-write-bound';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A known plaintext for this seat, committed — the rig issues its token through the command,
        // which prints the plaintext rather than returning it. Removed with the seat's rows at teardown.
        $this->token = 'mzn_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::connection(self::FIXTURE)->table('ingest_tokens')->insert([
            'token_hash' => hash('sha256', $this->token),
            'prefix' => substr($this->token, 0, 12),
            'seat_ref' => $this->seatRef,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'created_by' => 'suite',
        ]);
    }

    public function test_the_ingest_session_carries_the_bounds_and_no_other_session_does(): void
    {
        $ingest = null;

        BatchWriter::$afterLock = function () use (&$ingest) {
            BatchWriter::$afterLock = null;
            $ingest = DB::selectOne('SELECT @@SESSION.idle_transaction_timeout AS idle, @@SESSION.innodb_lock_wait_timeout AS lock_wait');
        };

        $this->postOn(self::WRITER_1, $this->body([$this->turnStart()]))->assertStatus(202);

        $this->assertNotNull($ingest, 'the seam never fired');
        $this->assertSame(IngestPipeline::IDLE_TRANSACTION_TIMEOUT_S, (int) $ingest->idle,
            "the ingest transaction's session is not bounded against an application that stops talking to the store");
        $this->assertSame(IngestPipeline::LOCK_WAIT_TIMEOUT_S, (int) $ingest->lock_wait);

        // Pinned on the ingest's session only: a session that never ran an ingest keeps the server's own.
        $other = DB::connection(self::PROBE)->selectOne(
            'SELECT @@SESSION.idle_transaction_timeout = @@GLOBAL.idle_transaction_timeout AS idle,'
            .' @@SESSION.innodb_lock_wait_timeout = @@GLOBAL.innodb_lock_wait_timeout AS lock_wait',
        );
        $this->assertSame(1, (int) $other->idle);
        $this->assertSame(1, (int) $other->lock_wait);
    }

    public function test_a_hung_ingest_transaction_is_ended_by_the_server_answers_503_and_its_retry_commits_once(): void
    {
        Exceptions::fake();

        $body = $this->body([$this->turnStart(), $this->toolStart($this->ulid())]);
        $lockFreedWhileTheIngestWasHung = null;

        BatchWriter::$afterLock = function (int $seatRef) use (&$lockFreedWhileTheIngestWasHung) {
            BatchWriter::$afterLock = null;

            // THE FIGURE IS LOWERED HERE, ON THE INGEST'S OWN SESSION, so the test waits one second
            // rather than the production bound; the test above pins the production value. Then the
            // application goes silent inside its transaction — the partition, from the server's side.
            DB::statement('SET SESSION idle_transaction_timeout = 1');
            usleep(2_500_000);

            // The ingest has not rolled back or disconnected — PHP is still inside the transaction — so
            // only the server can have released the seat's row.
            try {
                DB::connection(self::PROBE)->select('SELECT seat_ref FROM seat_state WHERE seat_ref = ? FOR UPDATE NOWAIT', [$seatRef]);
                $lockFreedWhileTheIngestWasHung = true;
            } catch (QueryException) {
                $lockFreedWhileTheIngestWasHung = false;
            }
        };

        $response = $this->postOn(self::WRITER_1, $body);

        $this->assertTrue($lockFreedWhileTheIngestWasHung, "the server did not end the idle ingest transaction and free the seat's lock");

        $response->assertStatus(503);
        $this->assertSame('server_error', $response->json('error'));
        $this->assertSame('store_failed', $response->json('detail'));
        $this->assertSame($body['batch_id'], $response->json('batch_id'));

        $store = DB::connection(self::FIXTURE);
        $this->assertFalse($store->table('batches')->where('batch_id', $body['batch_id'])->exists(), 'the ended transaction committed its batch');
        $this->assertSame(0, $store->table('events')->where('seat_ref', $this->seatRef)->count());
        $this->assertSame(1, $this->counter('batches_failed.store_failed'));
        $this->assertSame(0, $this->refusals(), 'a fault was counted as a refusal');

        // The flusher's retry, on a fresh request.
        DB::purge(self::WRITER_1);
        $this->postOn(self::WRITER_1, $body)->assertStatus(202)->assertJson(['accepted' => 2, 'duplicates' => 0]);

        $this->assertSame(1, $store->table('batches')->where('batch_id', $body['batch_id'])->count());
        $this->assertSame(2, $store->table('events')->where('seat_ref', $this->seatRef)->count());
    }

    public function test_a_concurrency_error_at_the_top_level_is_answered_as_contention_and_commits_nothing(): void
    {
        Exceptions::fake();

        $body = $this->body([$this->turnStart()]);

        BatchWriter::$afterFirstChunk = function () {
            BatchWriter::$afterFirstChunk = null;

            throw ConcurrencyError::raised(1205);
        };

        $response = $this->postOn(self::WRITER_1, $body);

        $response->assertStatus(503);
        $this->assertSame('store_contended', $response->json('detail'));

        $store = DB::connection(self::FIXTURE);
        $this->assertFalse($store->table('batches')->where('batch_id', $body['batch_id'])->exists());
        $this->assertSame(0, $store->table('events')->where('seat_ref', $this->seatRef)->count());
        $this->assertSame(1, $this->counter('batches_failed.store_contended'));
        $this->assertSame(0, $this->refusals(), 'a fault was counted as a refusal');

        // The top-level shape: Laravel rolled back and rethrew the engine's own exception.
        Exceptions::assertReported(QueryException::class);
    }

    /** Every `batches_refused.*` increment on this seat — a fault is never one. */
    private function refusals(): int
    {
        return (int) DB::connection(self::FIXTURE)->table('seat_counters')->where('seat_ref', $this->seatRef)
            ->where('name', 'like', 'batches\\_refused.%')->sum('value');
    }

    /** @param  array<string, mixed>  $body */
    private function postOn(string $connection, array $body): TestResponse
    {
        return $this->on($connection, fn () => $this->call(
            'POST',
            '/api/ingest/events',
            server: [
                'REMOTE_ADDR' => '203.0.113.10',
                'CONTENT_TYPE' => 'application/json; charset=utf-8',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            ],
            content: json_encode($body, JSON_UNESCAPED_SLASHES),
        ));
    }
}

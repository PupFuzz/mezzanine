<?php

namespace Tests\Feature\Ingest;

use App\Ingest\BatchWriter;
use App\Ingest\IngestPipeline;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\FoldTestCase;

/**
 * card#12530 — the session bounds an ingest request pins (`IngestPipeline::SESSION_BOUNDS`) end with
 * the request in the suite, as they do in production.
 *
 * In production a request's connection is its own: the stock non-persistent SAPI opens it for the
 * request and closes it after, so the bounds die with it. A test's request runs in the test's process
 * on the TEST's connection, the one holding `RefreshDatabase`'s transaction. Before card#12530 the
 * bounds therefore stayed on that connection for the rest of the test, and a test that then sat idle
 * longer than `IDLE_TRANSACTION_TIMEOUT_S` inside its own transaction had its connection ended by the
 * server: `2006 MySQL server has gone away` at the teardown rollback. Run 40727257707 attempt 1 failed
 * that way, in `Tests\Feature\Building\TheClientHoldsRoomMapsByVersionTest`, whose shipped-client probes
 * ran past the bound after its last statement on a slow runner.
 *
 * `Tests\TestCase::call()` is the seam that restores them; this file asserts it does.
 */
class AnIngestRequestsSessionBoundsEndWithTheRequestTest extends FoldTestCase
{
    protected function tearDown(): void
    {
        BatchWriter::$afterLock = null;

        parent::tearDown();
    }

    /** @return array<string, int> the default connection's own values of every bound the ingest pins */
    private function sessionValues(): array
    {
        $values = [];

        foreach (array_keys(IngestPipeline::SESSION_BOUNDS) as $variable) {
            $values[$variable] = (int) DB::selectOne("SELECT @@SESSION.{$variable} AS v")->v;
        }

        return $values;
    }

    public function test_the_tests_connection_gets_back_the_values_it_had_before_an_ingest_request(): void
    {
        // A value of the test's own on one bound, so a restore to the server's default instead of to
        // what the session held would show; the other keeps the server's own.
        DB::statement('SET SESSION innodb_lock_wait_timeout = 7');
        $before = $this->sessionValues();

        $during = null;
        BatchWriter::$afterLock = function () use (&$during) {
            BatchWriter::$afterLock = null;
            $during = $this->sessionValues();
        };

        $this->deliver($this->cleanTurn());

        // The request really did pin them, on this same connection — without this the assertion below
        // would pass for a request that never reached the write.
        $this->assertSame(IngestPipeline::SESSION_BOUNDS, $during, 'the ingest did not pin its bounds on the connection it shares with the test');

        $this->assertSame($before, $this->sessionValues(),
            "the ingest request's session bounds outlived the request on the test's connection");
    }
}

<?php

namespace Tests\Feature\Fold;

use App\Fold\Fold;
use App\Ingest\BatchWriter;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * AT-D2-22 — concurrent ingest cannot strand an event behind the cursor, DRIVEN on real connections
 * (card#9398).
 *
 * `At22CursorSafetyTest` runs on the suite's one connection, where two overlapping same-seat write
 * transactions cannot exist. Here each actor has its own connection and its own COMMIT
 * (`CommittedSeatTestCase`), so the interleaving `docs/design/FLEET-STATE.md § 11` names is built
 * exactly: writer 1 inserts its events and is held open; writer 2 writes the same seat; a fold pass
 * runs; writer 1 commits.
 *
 * The property under test is § 6.5's: for one seat, id-assignment order and commit order are the same
 * order, because every `events` insert happens under that seat's `seat_state` row lock, taken as the
 * ingest transaction's FIRST statement. The first test drives the strand that property closes; the
 * second pins WHERE the lock is taken, because a lock taken at the end of the transaction would still
 * pass a test that only looks at the end state of an uncontended write.
 *
 * ⚠ One interleaving per run, not the twenty § 11 once asked for: the seams make the interleaving
 * deterministic, so a repeat drives the identical statement order and adds no evidence.
 */
class At22LockFirstIngestTest extends CommittedSeatTestCase
{
    protected function install(): string
    {
        return 'at22-lock-first';
    }

    public function test_a_same_seat_write_overlapping_an_open_one_strands_no_event_behind_the_cursor(): void
    {
        $callOne = $this->ulid();
        $callTwo = $this->ulid();
        $writerOne = $this->batch([$this->turnStart(), $this->toolStart($callOne)]);
        $writerTwo = $this->batch([$this->toolStart($callTwo)]);

        $writerTwoFirstAttempt = null;
        $seamFired = false;

        // Writer 1 has inserted its events — its ids are assigned and NOT committed — when this runs.
        BatchWriter::$afterFirstChunk = function () use ($writerTwo, &$writerTwoFirstAttempt, &$seamFired) {
            BatchWriter::$afterFirstChunk = null;       // once: writer 2's own write passes this seam too
            $seamFired = true;

            DB::connection(self::WRITER_2)->statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                $this->write(self::WRITER_2, $writerTwo);
                $writerTwoFirstAttempt = 'committed';
            } catch (QueryException $e) {
                if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $writerTwoFirstAttempt = 'waited for the seat lock and timed out';
            }

            // Past the lag the fold read used to filter on, so a fold that still depends on it reads
            // writer 2's committed rows here rather than reading nothing for a reason of its own.
            $this->advanceServerClock(Fold::VISIBILITY_LAG_S + 1);

            $this->foldPass(self::FOLD);
        };

        $this->write(self::WRITER_1, $writerOne);

        $this->assertTrue($seamFired, 'the interleaving was never driven');

        if ($writerTwoFirstAttempt !== 'committed') {
            // The flusher's retry (D1 § 10.3), once the seat lock is free.
            $this->write(self::WRITER_2, $writerTwo);
        }

        $this->foldUntilCaughtUp(self::FOLD);

        $store = DB::connection(self::FIXTURE);
        $folded = $store->table('calls')->where('seat_ref', $this->seatRef)->pluck('call_id')->all();

        // THE APPLIED EVENT SET, NOT THE FINAL CURSOR: a stranded event sits BELOW the cursor, so the
        // cursor reads "caught up" either way. Each writer opened one call; both calls must exist.
        $this->assertContains($callOne, $folded,
            "writer 1's events were stranded below the cursor (writer 2's first attempt: $writerTwoFirstAttempt)");
        $this->assertContains($callTwo, $folded, "writer 2's events were never folded");

        $state = $this->state();
        $this->assertSame((int) $store->table('events')->where('seat_ref', $this->seatRef)->max('id'), (int) $state->head_event_id);
        $this->assertSame((int) $state->head_event_id, (int) $state->fold_cursor_event_id);
        $this->assertSame(0, (int) $state->fold_errors);

        // And the mechanism: writer 2 could not write the seat while writer 1 held it.
        $this->assertSame('waited for the seat lock and timed out', $writerTwoFirstAttempt);
    }

    public function test_the_ingest_takes_the_seat_lock_before_it_writes_anything(): void
    {
        $writer = $this->batch([$this->turnStart()]);
        $batchId = $writer[0]->batchId;

        $seamFired = false;
        $batchRowWritten = null;
        $probeRefused = null;

        BatchWriter::$afterLock = function (int $seatRef) use ($batchId, &$seamFired, &$batchRowWritten, &$probeRefused) {
            BatchWriter::$afterLock = null;
            $seamFired = true;

            // Read on the WRITER's own connection (the default here), the only one that can see its
            // uncommitted rows. A lock taken after the inserts would find this row already written.
            $batchRowWritten = DB::table('batches')->where('seat_ref', $seatRef)->where('batch_id', $batchId)->exists();

            // Raw SQL: the query builder has no NOWAIT. The assertion is that it throws — not which
            // error the engine reports for a row another transaction holds.
            try {
                DB::connection(self::PROBE)->select(
                    'SELECT seat_ref FROM seat_state WHERE seat_ref = ? FOR UPDATE NOWAIT', [$seatRef],
                );
                $probeRefused = false;
            } catch (QueryException) {
                $probeRefused = true;
            }
        };

        $this->write(self::WRITER_1, $writer);

        $this->assertTrue($seamFired, 'the seam never fired');
        $this->assertFalse($batchRowWritten, 'the seat lock was taken after the batch row was written, not first');
        $this->assertTrue($probeRefused, "another connection locked the seat's seat_state row while the ingest held its transaction open");
    }
}

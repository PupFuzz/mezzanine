<?php

namespace Tests\Feature\Feed;

use App\Feed\Outbox;
use App\Feed\SeatRetired;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\CommittedSeatTestCase;
use Tests\Feature\Fold\ConcurrencyError;

/**
 * A retried `Outbox::transaction()` inserts only what its successful attempt enqueued — card#9466.
 *
 * `DB::transaction()` runs the callback once per attempt. An attempt that enqueued a message and then
 * hit a concurrency error has its rows rolled back, and its message must go with them: left queued,
 * the next attempt would insert it beside its own, and every client would be told the same thing
 * twice.
 *
 * On a committed connection, because a retry happens only in an outermost transaction —
 * `RefreshDatabase`'s own transaction would put this one at level 2, where Laravel rethrows instead.
 */
class OutboxRetryTest extends CommittedSeatTestCase
{
    protected function install(): string
    {
        return 'outbox-retry';
    }

    public function test_a_retried_transaction_inserts_only_what_its_last_attempt_enqueued(): void
    {
        $attempt = 0;

        $this->on(self::WRITER_1, function () use (&$attempt) {
            Outbox::transaction(function () use (&$attempt) {
                $attempt++;

                Outbox::enqueue(new SeatRetired(
                    $this->seatRef, $this->install(), $this->seat(), '2026-08-26 12:00:00.000', 'suite', 'attempt '.$attempt, 1,
                ));

                if ($attempt === 1) {
                    throw ConcurrencyError::raised(1213);
                }
            }, 2);
        });

        $this->assertSame(2, $attempt, 'the transaction was not retried');

        $rows = DB::connection(self::FIXTURE)->table('feed_outbox')
            ->where('install_id', $this->install())->pluck('message')->all();

        $this->assertCount(1, $rows, 'the rolled-back attempt\'s message was inserted beside the retry\'s');
        $this->assertStringContainsString('attempt 2', $rows[0]);
    }
}

<?php

namespace Tests\Feature\Fold;

use App\Console\Commands\RebuildCommand;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A rebuild that times out on the seat lock is retried from a clean rollback, and succeeds once the
 * holder lets go — card#9466, `RebuildCommand::REPLAY_LOCK_ATTEMPTS`.
 *
 * The holder is released from inside the rebuild's SECOND attempt, before that attempt's lock
 * statement, so the first attempt genuinely times out and the second genuinely gets the lock. No
 * sleep decides the interleaving.
 */
class RebuildCommandBoundedRetryTest extends CommittedSeatTestCase
{
    protected function install(): string
    {
        return 'rebuild-retry';
    }

    public function test_a_blocked_rebuild_retries_and_succeeds_once_the_holder_releases(): void
    {
        $holder = DB::connection(self::WRITER_1);
        $holder->beginTransaction();

        try {
            $holder->select('SELECT seat_ref FROM seat_state WHERE seat_ref = ? FOR UPDATE', [$this->seatRef]);

            DB::connection(self::WRITER_2)->statement('SET SESSION innodb_lock_wait_timeout = 1');

            $attempt = 0;
            RebuildCommand::$beforeReset = function () use (&$attempt, $holder) {
                $attempt++;

                if ($attempt === 2) {
                    $holder->commit();
                }
            };

            $exit = null;
            $thrown = null;

            try {
                $exit = $this->on(self::WRITER_2, fn () => Artisan::call('mezzanine:rebuild', [
                    '--seat' => $this->install().'/'.$this->seat(),
                ]));
            } catch (QueryException $e) {
                $thrown = $e;
            }

            $this->assertSame(2, $attempt, 'the rebuild did not retry');
            $this->assertNull($thrown, 'the retried rebuild still failed: '.$thrown?->getMessage());
            $this->assertSame(0, $exit);
            $this->assertSame(1, $this->counter('state_rebuilds'), 'the rolled-back attempt left a count behind');
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }
        }
    }
}

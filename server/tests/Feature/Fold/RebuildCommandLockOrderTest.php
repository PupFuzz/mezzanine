<?php

namespace Tests\Feature\Fold;

use App\Console\Commands\RebuildCommand;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * `mezzanine:rebuild` takes the seat's `seat_state` row lock before it deletes anything — card#9466,
 * `docs/design/FLEET-STATE.md § 6.5`'s lock-first rule applied to `§ 6.6`'s replay.
 *
 * The end state of a rebuild is the same whether the lock is taken first or at `reset()`'s own
 * `seat_state` UPDATE, so this pins WHERE: right after the first DELETE, a second connection asks for
 * the row with `NOWAIT` and must be refused.
 */
class RebuildCommandLockOrderTest extends CommittedSeatTestCase
{
    protected function install(): string
    {
        return 'rebuild-lock-order';
    }

    public function test_the_rebuild_takes_the_seat_lock_before_its_first_delete(): void
    {
        $probeRefused = null;

        RebuildCommand::$afterFirstDelete = function (int $seatRef) use (&$probeRefused) {
            try {
                DB::connection(self::PROBE)->select(
                    'SELECT seat_ref FROM seat_state WHERE seat_ref = ? FOR UPDATE NOWAIT', [$seatRef],
                );
                $probeRefused = false;
            } catch (QueryException $e) {
                if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $probeRefused = true;
            }
        };

        $exit = $this->on(self::WRITER_1, fn () => Artisan::call('mezzanine:rebuild', [
            '--seat' => $this->install().'/'.$this->seat(),
        ]));

        $this->assertNotNull($probeRefused, 'the seam after the first DELETE never ran');
        $this->assertTrue($probeRefused, 'seat_state was not yet locked right after the first DELETE');
        $this->assertSame(0, $exit);
    }
}

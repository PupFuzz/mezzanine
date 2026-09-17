<?php

namespace Tests\Feature\Fleet;

use App\Fleet\SeatRetirement;
use App\Fleet\SeatRetirementOutcome;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\CommittedSeatTestCase;

/**
 * Retirement holds the seat's `seat_state` row lock by the time it samples `$before` — card#9466,
 * `docs/design/FLEET-STATE.md § 6.5`'s lock-first rule.
 *
 * Right after the sample, another connection tries to write the seat's row with a 1 s wait. Holding
 * the lock, retirement makes that write time out; without it, the write commits under the sample,
 * which is the window the lock closes.
 */
class SeatRetirementLockOrderTest extends CommittedSeatTestCase
{
    protected function install(): string
    {
        return 'retire-lock-order';
    }

    public function test_retirement_takes_the_seat_lock_before_sampling_before(): void
    {
        $timedOut = null;

        SeatRetirement::$afterBefore = function (int $seatRef) use (&$timedOut) {
            DB::connection(self::WRITER_1)->statement('SET SESSION innodb_lock_wait_timeout = 1');

            try {
                DB::connection(self::WRITER_1)->statement(
                    'UPDATE seat_state SET state_version = state_version WHERE seat_ref = ?', [$seatRef],
                );
                $timedOut = false;
            } catch (QueryException $e) {
                if (! app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                    throw $e;
                }

                $timedOut = true;
            }
        };

        $outcome = $this->on(self::WRITER_2, fn () => app(SeatRetirement::class)->retire(
            $this->install(), $this->seat(), 'operator@example.com', 'lock order',
        ));

        $this->assertNotNull($timedOut, 'the seam after $before never ran');
        $this->assertTrue($timedOut, 'a concurrent writer was not blocked by the seat lock at $before-sample time');
        $this->assertSame(SeatRetirementOutcome::RETIRED, $outcome->outcome);
    }
}

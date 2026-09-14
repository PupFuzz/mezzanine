<?php

namespace Tests\Feature\Fleet;

use App\Fleet\SeatRetirement;
use App\Fleet\SeatRetirementOutcome;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\CommittedSeatTestCase;

/**
 * Retirement's bounded wait and retry — card#9466, `SeatRetirement::LOCK_WAIT_TIMEOUT_S` and
 * `LOCK_ATTEMPTS`.
 *
 * Each property is one line of the act, and each has its own test:
 *   · a retirement that times out on the seat lock is retried, and succeeds once the holder lets go;
 *   · against a holder that never lets go, it gives up in seconds on a session whose own wait is
 *     the server's much longer default — the pin is what bounds it;
 *   · the session's wait is what it was before the call — after a retirement, and after one that
 *     gave up, each its own test because they leave the act by different exits.
 *
 * Every retirement runs on WRITER_2, whose wait this file never sets: it is the server's default,
 * and the tests assert that default is far above the pin before they rely on it.
 */
class SeatRetirementBoundedRetryTest extends CommittedSeatTestCase
{
    protected function install(): string
    {
        return 'retire-retry';
    }

    public function test_a_blocked_retirement_retries_and_succeeds_once_the_holder_releases(): void
    {
        $holder = $this->holdTheSeat();

        try {
            $attempt = 0;
            SeatRetirement::$beforeRetire = function () use (&$attempt, $holder) {
                $attempt++;

                if ($attempt === 2) {
                    $holder->commit();
                }
            };

            [$outcome, $thrown] = $this->retire();

            $this->assertSame(2, $attempt, 'the retirement did not retry');
            $this->assertNull($thrown, 'the retried retirement still failed: '.$thrown?->getMessage());
            $this->assertSame(SeatRetirementOutcome::RETIRED, $outcome->outcome);
        } finally {
            $this->release($holder);
        }
    }

    public function test_a_retirement_against_a_seat_held_throughout_gives_up_within_its_pinned_bound(): void
    {
        $this->assertGreaterThan(7, $this->sessionWait(), 'the server default is too short for this test to discriminate');

        $holder = $this->holdTheSeat();

        try {
            $started = microtime(true);
            [$outcome, $thrown] = $this->retire();
            $elapsed = microtime(true) - $started;

            $this->assertLessThan(7.0, $elapsed, 'the retirement waited past its pinned bound');
            $this->assertNull($outcome);
            $this->assertNotNull($thrown);
            $this->assertTrue(app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($thrown));
        } finally {
            $this->release($holder);
        }
    }

    public function test_the_session_wait_is_restored_after_a_retirement(): void
    {
        $wait = $this->sessionWait();
        $this->assertNotSame(SeatRetirement::LOCK_WAIT_TIMEOUT_S, $wait, 'the default equals the pin, so a missing restore could not show');

        [$outcome] = $this->retire();

        $this->assertSame(SeatRetirementOutcome::RETIRED, $outcome->outcome);
        $this->assertSame($wait, $this->sessionWait(), 'the retirement left its short wait on the session');
    }

    public function test_the_session_wait_is_restored_after_a_retirement_that_gave_up(): void
    {
        $wait = $this->sessionWait();
        $this->assertNotSame(SeatRetirement::LOCK_WAIT_TIMEOUT_S, $wait, 'the default equals the pin, so a missing restore could not show');

        $holder = $this->holdTheSeat();

        try {
            [, $thrown] = $this->retire();

            $this->assertNotNull($thrown, 'the retirement did not give up');
            $this->assertSame($wait, $this->sessionWait(), 'the retirement that gave up left its short wait on the session');
        } finally {
            $this->release($holder);
        }
    }

    /** WRITER_1 holds this seat's `seat_state` row in an open transaction. */
    private function holdTheSeat(): Connection
    {
        $holder = DB::connection(self::WRITER_1);
        $holder->beginTransaction();
        $holder->select('SELECT seat_ref FROM seat_state WHERE seat_ref = ? FOR UPDATE', [$this->seatRef]);

        return $holder;
    }

    private function release(Connection $holder): void
    {
        if ($holder->transactionLevel() > 0) {
            $holder->rollBack();
        }
    }

    /** @return array{?SeatRetirementOutcome, ?QueryException} */
    private function retire(): array
    {
        try {
            return [$this->on(self::WRITER_2, fn () => app(SeatRetirement::class)->retire(
                $this->install(), $this->seat(), 'operator@example.com', 'bounded retry',
            )), null];
        } catch (QueryException $e) {
            return [null, $e];
        }
    }

    private function sessionWait(): int
    {
        return (int) DB::connection(self::WRITER_2)->selectOne('SELECT @@session.innodb_lock_wait_timeout AS v')->v;
    }
}

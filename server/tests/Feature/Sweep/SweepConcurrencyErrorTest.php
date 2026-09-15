<?php

namespace Tests\Feature\Sweep;

use App\Sweep\Sweep;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\CommittedSeatTestCase;

/**
 * The sweeper yields a seat another writer holds, below ERROR, and counts it — card#9466,
 * `docs/design/FLEET-STATE.md § 2.2`'s sweep-contention row and § 7.2's `sweep_seat_contended`.
 *
 * On real connections, because contention needs a second transaction. Both tests assert on THIS
 * test's seat's counters only: `Sweep::pass()` visits every seat in the store, and a pass-wide
 * `failed` would be a statement about seats this test does not own.
 *
 * The sweep's own session wait is pinned to 1 s, so a red run (the fix reverted, the sweep now
 * WAITING on the held row) fails in about a second rather than the server's default.
 */
class SweepConcurrencyErrorTest extends CommittedSeatTestCase
{
    /** @var array{plane: list<object>, fleetPredicates: list<object>} */
    private array $fleetState;

    protected function install(): string
    {
        return 'sweep-contended';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fleetState = $this->snapshotFleetState();

        // A seat that has reported and been folded, so its sweep pass has every fact it reads.
        $this->write(self::FIXTURE, $this->batch([$this->turnStart(), $this->toolStart($this->ulid())]));
        $this->foldPass(self::FOLD);
    }

    protected function tearDown(): void
    {
        $this->restoreFleetState($this->fleetState);

        parent::tearDown();
    }

    public function test_a_seat_locked_by_another_writer_is_skipped_immediately_not_waited_on(): void
    {
        $holder = DB::connection(self::WRITER_1);
        $holder->beginTransaction();

        try {
            $holder->select('SELECT seat_ref FROM seat_state WHERE seat_ref = ? FOR UPDATE', [$this->seatRef]);

            DB::connection(self::SWEEP)->statement('SET SESSION innodb_lock_wait_timeout = 1');

            $started = microtime(true);
            $this->on(self::SWEEP, fn () => app(Sweep::class)->pass());
            $elapsed = microtime(true) - $started;

            $this->assertLessThan(1.0, $elapsed, 'the sweep waited instead of skipping');
            $this->assertSame(0, $this->counter('sweep_seat_error'));
            $this->assertSame(1, $this->counter('sweep_seat_contended'));
        } finally {
            $holder->rollBack();
        }
    }

    public function test_a_downstream_race_the_lock_does_not_cover_still_yields_below_error(): void
    {
        $holder = DB::connection(self::WRITER_1);
        $holder->beginTransaction();

        try {
            // A `sessions` row of this seat, held by a writer that never took the seat lock — the
            // seat lock the sweep takes first does not reach it.
            $held = $holder->select('SELECT id FROM sessions WHERE seat_ref = ? FOR UPDATE', [$this->seatRef]);
            $this->assertNotEmpty($held, 'setup produced no sessions row for the holder to lock');

            DB::connection(self::SWEEP)->statement('SET SESSION innodb_lock_wait_timeout = 1');

            $this->on(self::SWEEP, fn () => app(Sweep::class)->pass());

            $this->assertSame(0, $this->counter('sweep_seat_error'), 'a downstream 1205 was counted as a failure instead of yielded');
            $this->assertSame(1, $this->counter('sweep_seat_contended'), 'the downstream race did not surface as contention');
        } finally {
            $holder->rollBack();
        }
    }
}

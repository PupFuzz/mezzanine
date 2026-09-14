<?php

namespace Tests\Feature\Fold;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * A rebuild against a seat another writer holds waits on `seat_state`, never on a projection row it
 * has already started deleting — card#9466.
 *
 * The other writer holds the seat's `seat_state` row AND one of its `calls` rows, the shape of a
 * writer that took the seat lock first and then touched a projection. A rebuild that locks first
 * blocks on its very first statement, holding nothing. One that deletes first gets past
 * `attention_requests` (nothing holds it) and blocks on `calls`, already holding the rows it deleted —
 * the half of an AB–BA cycle § 6.5's lock-first rule removes.
 */
class RebuildBlocksOnSeatStateTest extends CommittedSeatTestCase
{
    protected function install(): string
    {
        return 'rebuild-blocks';
    }

    public function test_a_rebuild_against_a_seat_another_writer_holds_blocks_on_seat_state_not_a_delete(): void
    {
        // A real, committed `calls` row for the holder to lock, made by this rig's own ingest and
        // fold. Asserted, because the discrimination below rests entirely on it being there.
        $this->write(self::FIXTURE, $this->batch([$this->turnStart(), $this->toolStart($this->ulid())]));
        $this->foldPass(self::FOLD);

        $this->assertTrue(
            DB::connection(self::FIXTURE)->table('calls')->where('seat_ref', $this->seatRef)->exists(),
            'setup produced no calls row for the holder to lock',
        );

        $holder = DB::connection(self::WRITER_1);
        $holder->beginTransaction();

        try {
            $holder->select('SELECT seat_ref FROM seat_state WHERE seat_ref = ? FOR UPDATE', [$this->seatRef]);
            $holder->select('SELECT id FROM calls WHERE seat_ref = ? FOR UPDATE', [$this->seatRef]);

            DB::connection(self::WRITER_2)->statement('SET SESSION innodb_lock_wait_timeout = 1');

            $blockedOn = null;

            try {
                $this->on(self::WRITER_2, fn () => Artisan::call('mezzanine:rebuild', [
                    '--seat' => $this->install().'/'.$this->seat(),
                ]));
            } catch (QueryException $e) {
                $blockedOn = $e->getSql();
            }

            $this->assertNotNull($blockedOn, 'the rebuild did not block on a held row');
            $this->assertStringContainsStringIgnoringCase('seat_state', $blockedOn, 'the rebuild blocked on a projection row, not on the seat lock');
        } finally {
            $holder->rollBack();
        }
    }
}

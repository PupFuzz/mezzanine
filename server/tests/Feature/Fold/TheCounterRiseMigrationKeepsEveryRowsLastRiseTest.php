<?php

namespace Tests\Feature\Fold;

use App\Ingest\Counters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * card#9491 · `seat_counters.last_increased_at`'s migration dates every existing row's last rise from
 * its `updated_at`, and a row an older build inserts after a rollback (NULL) is stamped by the next
 * increment rather than kept NULL for good.
 *
 * ⚠ IT RUNS THE MIGRATION BY HAND, as `Tests\Feature\Admin\TheRoleMigrationKeepsEveryoneAnOperatorTest`
 * does and for its reason: `RefreshDatabase` applied it to an EMPTY table, where the backfill has
 * nothing to do. DDL implicitly commits on MariaDB, so the arm cleans up explicitly.
 */
class TheCounterRiseMigrationKeepsEveryRowsLastRiseTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_08_000000_add_last_increased_at_to_seat_counters.php');
    }

    protected function tearDown(): void
    {
        DB::table('seat_counters')->delete();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_existing_row_is_dated_at_its_updated_at(): void
    {
        $migration = $this->migration();
        $migration->down();

        // The row as the schema before card#9491 wrote it.
        DB::table('seat_counters')->insert([
            'seat_ref' => 7, 'name' => 'seq_epoch_change', 'value' => 1, 'updated_at' => '2026-09-13 19:36:14.610',
        ]);

        $migration->up();

        $this->assertSame('2026-09-13 19:36:14.610', DB::table('seat_counters')
            ->where('seat_ref', 7)->where('name', 'seq_epoch_change')->value('last_increased_at'));
    }

    public function test_a_row_an_older_build_inserted_is_stamped_by_the_next_increment(): void
    {
        // What an older build's insert leaves after a rollback: the column it does not know is NULL.
        DB::table('seat_counters')->insert([
            'seat_ref' => 7, 'name' => 'seq_gap', 'value' => 2, 'updated_at' => '2026-10-01 00:00:00.000',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00.000', 'UTC'));
        Counters::seat(7, 'seq_gap', 3, '2026-10-08 11:59:59.000');

        $row = DB::table('seat_counters')->where('seat_ref', 7)->where('name', 'seq_gap')->first();
        $this->assertSame(5, (int) $row->value);
        $this->assertSame('2026-10-08 11:59:59.000', $row->last_increased_at);

        // And an older receipt replayed after it never moves it back (a rebuild's replay).
        Counters::seat(7, 'seq_gap', 1, '2026-10-02 00:00:00.000');
        $this->assertSame('2026-10-08 11:59:59.000', DB::table('seat_counters')
            ->where('seat_ref', 7)->where('name', 'seq_gap')->value('last_increased_at'));
    }
}

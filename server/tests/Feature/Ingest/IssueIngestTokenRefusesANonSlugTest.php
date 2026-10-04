<?php

namespace Tests\Feature\Ingest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `mezzanine:ingest-token:issue` mints a credential bound to an install and a seat, and the ids it
 * is given are the ones every event that token carries must equal (D1 § 3.1, § 4.5). An id ending
 * in a line break is no slug: a seat config's `seat` can never equal `"aimla-pm\n"`, so a token
 * issued for it is dead on arrival (card#11253). It is refused before anything is written.
 */
final class IssueIngestTokenRefusesANonSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_install_id_ending_in_a_line_break_is_refused_and_nothing_is_written(): void
    {
        $this->artisan('mezzanine:ingest-token:issue', [
            'install_id' => "aimla\n",
            'seat_id' => 'aimla-pm',
            '--by' => 'suite',
        ])
            ->expectsOutputToContain('install_id "aimla\n" must match')
            ->doesntExpectOutputToContain('mzn_')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_a_seat_id_ending_in_a_line_break_is_refused_and_nothing_is_written(): void
    {
        $this->artisan('mezzanine:ingest-token:issue', [
            'install_id' => 'aimla',
            'seat_id' => "aimla-pm\n",
            '--by' => 'suite',
        ])
            ->expectsOutputToContain('seat_id "aimla-pm\n" must match')
            ->doesntExpectOutputToContain('mzn_')
            ->assertFailed();

        $this->assertNothingWritten();
    }

    public function test_the_same_ids_without_the_line_break_are_issued(): void
    {
        // The control: the refusals above are about the line break, not the ids.
        $this->artisan('mezzanine:ingest-token:issue', [
            'install_id' => 'aimla',
            'seat_id' => 'aimla-pm',
            '--by' => 'suite',
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('installs')->where('install_id', 'aimla')->count());
        $this->assertSame(1, DB::table('seats')->where('seat_id', 'aimla-pm')->count());
        $this->assertSame(1, DB::table('ingest_tokens')->count());
    }

    private function assertNothingWritten(): void
    {
        foreach (['installs', 'seats', 'seat_state', 'ingest_tokens'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table gained a row");
        }
    }
}

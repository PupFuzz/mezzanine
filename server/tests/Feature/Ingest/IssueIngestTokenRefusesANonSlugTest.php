<?php

namespace Tests\Feature\Ingest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `mezzanine:ingest-token:issue` mints a credential bound to a seat, and the seat id it is given is
 * the one every batch that token carries must equal (D1 § 3.1, § 3.3). An id ending in a line break
 * is no slug: a seat config's `seat` can never equal `"aimla-pm\n"`, so a token
 * issued for it is dead on arrival (card#11253). It is refused before anything is written.
 */
final class IssueIngestTokenRefusesANonSlugTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_seat_id_ending_in_a_line_break_is_refused_and_nothing_is_written(): void
    {
        $this->artisan('mezzanine:ingest-token:issue', [
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
        // The control: the refusal above is about the line break, not the id. Since card#13117 the
        // command takes the seat id alone, and the seat it creates is in no room.
        $this->artisan('mezzanine:ingest-token:issue', [
            'seat_id' => 'aimla-pm',
            '--by' => 'suite',
        ])->assertSuccessful();

        $this->assertSame(0, DB::table('installs')->count());
        $this->assertSame(1, DB::table('seats')->where('seat_id', 'aimla-pm')->whereNull('install_ref')->count());
        $this->assertSame(1, DB::table('ingest_tokens')->count());
    }

    private function assertNothingWritten(): void
    {
        foreach (['installs', 'seats', 'seat_state', 'ingest_tokens'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table gained a row");
        }
    }
}

<?php

namespace Tests\Feature\Ingest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `mezzanine:ingest-token:revoke`'s outcomes, pinned BEFORE its act moved into
 * `App\Fleet\SeatTokens` (card#12331), so the extraction has something to be identical to: no such
 * prefix, already revoked, revoked, and the dark-seat warning when the seat is left with no active
 * token — and its absence when another token is still active.
 */
final class RevokeIngestTokenTest extends TestCase
{
    use RefreshDatabase;

    private function issue(string $install = 'aimla', string $seat = 'aimla-pm'): string
    {
        $this->artisan('mezzanine:ingest-token:issue', [
            'install_id' => $install,
            'seat_id' => $seat,
            '--by' => 'suite',
        ])->assertSuccessful();

        return (string) DB::table('ingest_tokens')->orderByDesc('id')->value('prefix');
    }

    public function test_an_unknown_prefix_is_a_failure_and_revokes_nothing(): void
    {
        $this->issue();

        $this->artisan('mezzanine:ingest-token:revoke', ['prefix' => 'mzn_nothere1'])
            ->expectsOutputToContain('No token with prefix mzn_nothere1. Nothing was revoked.')
            ->assertFailed();

        $this->assertSame(0, DB::table('ingest_tokens')->whereNotNull('revoked_at')->count());
    }

    public function test_the_last_active_token_is_revoked_with_its_reason_and_the_dark_seat_is_named(): void
    {
        $prefix = $this->issue();

        $this->artisan('mezzanine:ingest-token:revoke', ['prefix' => $prefix, '--reason' => 'rotated'])
            ->expectsOutputToContain("revoked $prefix — aimla / aimla-pm")
            ->expectsOutputToContain('aimla / aimla-pm now has NO active token and cannot post. If this was a rotation, issue the new token first.')
            ->assertSuccessful();

        $row = DB::table('ingest_tokens')->where('prefix', $prefix)->first();
        $this->assertNotNull($row->revoked_at);
        $this->assertSame('rotated', $row->revoked_reason);
    }

    public function test_a_seat_that_keeps_another_active_token_gets_no_dark_seat_warning(): void
    {
        $old = $this->issue();
        $this->issue();

        $this->artisan('mezzanine:ingest-token:revoke', ['prefix' => $old])
            ->expectsOutputToContain("revoked $old — aimla / aimla-pm")
            ->doesntExpectOutputToContain('NO active token')
            ->assertSuccessful();

        $this->assertNull(DB::table('ingest_tokens')->where('prefix', $old)->value('revoked_reason'),
            'no --reason is stored as NULL, not as an empty string');
    }

    public function test_an_already_revoked_prefix_is_a_no_op_that_keeps_the_first_record(): void
    {
        $prefix = $this->issue();

        $this->artisan('mezzanine:ingest-token:revoke', ['prefix' => $prefix, '--reason' => 'first'])->assertSuccessful();
        $at = DB::table('ingest_tokens')->where('prefix', $prefix)->value('revoked_at');

        $this->artisan('mezzanine:ingest-token:revoke', ['prefix' => $prefix, '--reason' => 'second'])
            ->expectsOutputToContain("Token $prefix is already revoked. Nothing changed.")
            ->assertSuccessful();

        $row = DB::table('ingest_tokens')->where('prefix', $prefix)->first();
        $this->assertSame($at, $row->revoked_at);
        $this->assertSame('first', $row->revoked_reason);
    }
}
